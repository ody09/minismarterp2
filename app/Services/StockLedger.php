<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\StockOperation;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    public function receive(User $actor, string $key, string $reference, array $lines): StockOperation
    {
        return $this->post($actor, 'receipt', $key, $reference, $lines);
    }

    public function issue(User $actor, string $key, string $reference, array $lines): StockOperation
    {
        return $this->post($actor, 'issue', $key, $reference, $lines);
    }

    private function post(User $actor, string $type, string $key, string $reference, array $lines): StockOperation
    {
        $idField = $type === 'receipt' ? 'batch_id' : 'product_id';
        $data = Validator::make(['key' => strtolower($key), 'reference' => trim($reference), 'lines' => array_values($lines)], [
            'key' => ['required', 'uuid'], 'reference' => ['required', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.'.$idField => ['bail', 'required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'lines.*.unit' => ['required', 'string', Rule::in(['piece', 'carton'])],
        ])->validate();
        $lines = array_map(fn ($line) => [$idField => (int) $line[$idField], 'quantity' => (int) $line['quantity'], 'unit' => $line['unit']], $data['lines']);

        return DB::transaction(function () use ($actor, $type, $data, $lines) {
            $warehouse = $this->lockContext($actor, 'inventory.post');
            $hash = $this->fingerprint([$actor->id, $warehouse->id, $type, $data['reference'], $lines]);
            if ($replay = $this->replay($data['key'], $hash)) {
                return $replay;
            }
            if (StockOperation::where('type', $type)->where('reference', $data['reference'])->lockForUpdate()->first(['id'])) {
                $this->invalid('reference', 'source_used');
            }
            $batchIds = $type === 'receipt' ? array_column($lines, 'batch_id') : [];
            $products = $type === 'receipt'
                ? Product::join('product_batches', 'product_batches.product_id', '=', 'products.id')->whereIn('product_batches.id', $batchIds)
                    ->select('products.*')->orderBy('products.id')->lockForUpdate()->get()->keyBy('id')
                : Product::whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batches = $type === 'receipt'
                ? ProductBatch::whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $now = CarbonImmutable::now('UTC');
            $today = $now->setTimezone(config('inventory.timezone'))->toDateString();
            $operation = StockOperation::create(['warehouse_id' => $warehouse->id, 'user_id' => $actor->id,
                'type' => $type, 'idempotency_key' => $data['key'], 'payload_hash' => $hash,
                'reference' => $data['reference'], 'posted_at' => $now]);

            foreach ($lines as $index => $line) {
                $batch = $type === 'receipt' ? $batches->get($line['batch_id']) : null;
                $product = $products->get($type === 'receipt' ? $batch?->product_id : $line['product_id']);
                if (! $product || ! $product->is_active || ($type === 'receipt' && ! $batch)) {
                    $this->invalid('lines', 'inactive_or_missing');
                }
                $pieces = $product->toPieces($line['quantity'], $line['unit']);
                $factor = $line['unit'] === 'carton' ? $product->pieces_per_carton : 1;

                if ($type === 'receipt') {
                    if ($batch->expiration_date && $batch->expiration_date->toDateString() < $today) {
                        $this->invalid('lines', 'expired');
                    }
                    $balance = Inventory::where('warehouse_id', $warehouse->id)->where('batch_id', $batch->id)->lockForUpdate()->first()
                        ?? Inventory::create(['warehouse_id' => $warehouse->id, 'batch_id' => $batch->id, 'quantity' => 0]);
                    $this->move($operation, $balance, $product->id, $pieces, $index + 1, 1, $line['quantity'], $line['unit'], $factor);
                    if (! $batch->stock_started_at) {
                        $batch->forceFill(['stock_started_at' => $now])->save();
                    }
                } else {
                    $balances = Inventory::where('warehouse_id', $warehouse->id)->where('quantity', '>', 0)
                        ->join('product_batches', 'product_batches.id', '=', 'inventory.batch_id')->where('product_batches.product_id', $product->id)
                        ->where(fn ($query) => $query->whereNull('product_batches.expiration_date')->orWhere('product_batches.expiration_date', '>=', $today))
                        ->select('inventory.*')->orderByRaw('product_batches.expiration_date IS NULL')
                        ->orderBy('product_batches.expiration_date')->orderBy('inventory.id')->lockForUpdate()->get();
                    $remaining = $pieces;
                    $allocation = 0;
                    foreach ($balances as $balance) {
                        if ($remaining === 0) {
                            break;
                        }
                        $take = min($remaining, $balance->quantity);
                        $this->move($operation, $balance, $product->id, -$take, $index + 1, ++$allocation, $line['quantity'], $line['unit'], $factor);
                        $remaining -= $take;
                    }
                    if ($remaining > 0) {
                        $this->invalid('lines', 'insufficient');
                    }
                }
                if (! $product->stock_started_at) {
                    $product->forceFill(['stock_started_at' => $now])->save();
                }
            }

            return $operation->load('transactions');
        }, 3);
    }

    public function reverse(User $actor, string $key, StockOperation $original, string $reason): StockOperation
    {
        $data = Validator::make(['key' => strtolower($key), 'reason' => trim($reason)], [
            'key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $original, $data) {
            $warehouse = $this->lockContext($actor, 'inventory.reverse');
            $hash = $this->fingerprint([$actor->id, $warehouse->id, 'reversal', $original->id, $data['reason']]);
            if ($replay = $this->replay($data['key'], $hash)) {
                return $replay;
            }
            $original = StockOperation::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $alreadyReversed = StockOperation::where('reversal_of_id', $original->id)->lockForUpdate()->first(['id']);
            if ($original->warehouse_id !== $warehouse->id || $original->type === 'reversal' || $alreadyReversed) {
                $this->invalid('operation', 'already_reversed');
            }
            Product::join('product_batches', 'product_batches.product_id', '=', 'products.id')
                ->join('inventory', 'inventory.batch_id', '=', 'product_batches.id')
                ->join('inventory_transactions', 'inventory_transactions.inventory_id', '=', 'inventory.id')
                ->where('inventory_transactions.stock_operation_id', $original->id)
                ->select('products.*')->orderBy('products.id')->lockForUpdate()->get();
            $moves = InventoryTransaction::where('stock_operation_id', $original->id)->orderBy('id')->lockForUpdate()->get();
            $operation = StockOperation::create(['warehouse_id' => $warehouse->id, 'user_id' => $actor->id,
                'type' => 'reversal', 'idempotency_key' => $data['key'], 'payload_hash' => $hash,
                'reference' => 'REV:'.$original->id, 'reason' => $data['reason'], 'reversal_of_id' => $original->id,
                'posted_at' => CarbonImmutable::now('UTC')]);
            foreach ($moves as $move) {
                $balance = Inventory::whereKey($move->inventory_id)->lockForUpdate()->firstOrFail();
                $batch = ProductBatch::whereKey($balance->batch_id)->lockForUpdate()->firstOrFail();
                $this->move($operation, $balance, $batch->product_id, -$move->quantity, $move->line_number, $move->allocation_number,
                    abs($move->quantity), 'piece', 1, $move->id);
            }

            return $operation->load('transactions');
        }, 3);
    }

    private function lockContext(User $actor, string $permission): Warehouse
    {
        // Match AccessManager's first lock, then serialize stock posting. All
        // invariant reads must be current even inside an older document snapshot.
        Role::where('code', 'admin')->where('is_system', true)->lockForUpdate()->firstOrFail();
        $warehouse = Warehouse::where('code', 'main')->lockForUpdate()->firstOrFail();
        $freshActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $allowed = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $freshActor->role_id)->where('permissions.code', $permission)
            ->lockForUpdate()->first(['permissions.id']);
        Gate::forUser($freshActor)->allowIf(fn (User $user) => $user->is_active && $allowed !== null);
        abort_unless($freshActor->auth_version === $actor->auth_version, 403);

        return $warehouse;
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(string $key, string $hash): ?StockOperation
    {
        $operation = StockOperation::where('idempotency_key', $key)->lockForUpdate()->first();
        if ($operation && ! hash_equals($operation->payload_hash, $hash)) {
            $this->invalid('key', 'key_conflict');
        }
        if ($operation) {
            $operation->setRelation('transactions', InventoryTransaction::where('stock_operation_id', $operation->id)->orderBy('id')->lockForUpdate()->get());
        }

        return $operation;
    }

    private function move(StockOperation $operation, Inventory $balance, int $productId, int $delta, int $line, int $allocation, int $inputQuantity, string $unit, int $factor, ?int $reversalOf = null): void
    {
        if ($balance->quantity < 0 || ($delta > 0 && $balance->quantity > PHP_INT_MAX - $delta)) {
            $this->invalid('lines', 'overflow');
        }
        if ($delta > 0) {
            $rows = Inventory::join('product_batches', 'product_batches.id', '=', 'inventory.batch_id')
                ->where('inventory.warehouse_id', $balance->warehouse_id)->where('product_batches.product_id', $productId)
                ->select('inventory.quantity')->orderBy('inventory.id')->lockForUpdate()->get();
            $total = 0;
            foreach ($rows as $row) {
                if ($row->quantity < 0 || $total > PHP_INT_MAX - $row->quantity) {
                    $this->invalid('lines', 'overflow');
                }
                $total += $row->quantity;
            }
            if ($total > PHP_INT_MAX - $delta) {
                $this->invalid('lines', 'overflow');
            }
        }
        $next = $balance->quantity + $delta;
        if ($next < 0) {
            $this->invalid('lines', 'insufficient');
        }
        InventoryTransaction::create(['stock_operation_id' => $operation->id, 'inventory_id' => $balance->id,
            'quantity' => $delta, 'line_number' => $line, 'allocation_number' => $allocation,
            'input_quantity' => $inputQuantity, 'unit' => $unit, 'conversion_factor' => $factor,
            'reversal_of_transaction_id' => $reversalOf]);
        $balance->quantity = $next;
        $balance->save();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('inventory.errors.'.$message)]);
    }
}
