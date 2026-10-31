<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrders
{
    private const MAX_AMOUNT = 999999999999999999;

    public function __construct(private StockContext $context) {}

    public function save(User $actor, array $input, ?PurchaseOrder $order = null): PurchaseOrder
    {
        $data = Validator::make($input, [
            'key' => [$order ? 'nullable' : 'required', 'uuid'],
            'version' => [$order ? 'required' : 'nullable', 'integer', 'min:1'],
            'supplier_id' => ['required', 'integer', 'min:1'], 'order_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'], 'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit' => ['required', 'string', Rule::in(['piece', 'carton'])],
            'items.*.unit_price' => ['required', function ($attribute, $value, $fail) {
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A\d{1,9}(?:\.\d{1,6})?\z/', (string) $value)) {
                    $fail(__('purchases.errors.price'));
                }
            }],
        ])->validate();
        $lines = array_map(fn ($line) => ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'],
            'unit' => $line['unit'], 'unit_price' => $this->decimal($this->micros((string) $line['unit_price']))], array_values($data['items']));
        $notes = trim($data['notes'] ?? '');
        $normalized = ['supplier_id' => (int) $data['supplier_id'], 'order_date' => $data['order_date'],
            'notes' => $notes === '' ? null : $notes];
        $hash = $this->hash([$actor->id, $normalized, $lines]);

        return DB::transaction(function () use ($actor, $order, $data, $lines, $normalized, $hash) {
            $this->context->lock($actor, ['purchases.manage']);
            if (! $order) {
                $key = strtolower($data['key']);
                if ($existing = PurchaseOrder::where('creation_key', $key)->lockForUpdate()->first()) {
                    if (! hash_equals($existing->creation_hash, $hash)) {
                        $this->invalid('key', 'key_conflict');
                    }

                    return $this->withItems($existing);
                }
            } else {
                $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->draft($order, (int) $data['version']);
            }
            $supplier = Supplier::whereKey($normalized['supplier_id'])->lockForUpdate()->first();
            if (! $supplier?->is_active) {
                $this->invalid('supplier_id', 'inactive');
            }
            $products = Product::whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($lines as $index => &$line) {
                $product = $products->get($line['product_id']);
                if (! $product?->is_active) {
                    $this->invalid('items.'.$index.'.product_id', 'inactive');
                }
                $price = $this->micros($line['unit_price']);
                if ($price > 0 && $line['quantity'] > intdiv(self::MAX_AMOUNT, $price)) {
                    $this->invalid('items.'.$index.'.unit_price', 'amount');
                }
                $subtotal = $price * $line['quantity'];
                if ($total > self::MAX_AMOUNT - $subtotal) {
                    $this->invalid('items', 'amount');
                }
                $total += $subtotal;
                $line += ['product_name' => $product->name, 'conversion_factor' => $line['unit'] === 'carton' ? $product->pieces_per_carton : 1,
                    'line_number' => $index + 1, 'subtotal' => $this->decimal($subtotal)];
            }
            unset($line);
            $values = [...$normalized, 'supplier_name' => $supplier->name, 'total_amount' => $this->decimal($total)];
            if ($order) {
                $order->fill([...$values, 'version' => $order->version + 1])->save();
                $order->items()->delete();
            } else {
                $order = PurchaseOrder::create([...$values, 'user_id' => $actor->id, 'status' => 'draft', 'version' => 1,
                    'creation_key' => $key, 'creation_hash' => $hash]);
            }
            $order->items()->createMany($lines);

            return $this->withItems($order);
        }, 3);
    }

    public function cancel(User $actor, PurchaseOrder $order, int $version): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order, $version) {
            $this->context->lock($actor, ['purchases.manage']);
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->draft($order, $version);
            $order->fill(['status' => 'cancelled', 'version' => $order->version + 1,
                'cancelled_by' => $actor->id, 'cancelled_at' => CarbonImmutable::now('UTC')])->save();

            return $this->withItems($order);
        }, 3);
    }

    public function receive(User $actor, PurchaseOrder $order, array $input): PurchaseOrder
    {
        $data = Validator::make($input, [
            'key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'array'],
            'items.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.batch_id' => ['nullable', 'integer', 'min:1'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.production_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expiration_date' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $assignments = array_map(function ($line) {
            $number = trim($line['batch_number'] ?? '');

            return ['item_id' => (int) $line['item_id'], 'batch_id' => ! empty($line['batch_id']) ? (int) $line['batch_id'] : null,
                'batch_number' => $number === '' ? null : $number, 'production_date' => $line['production_date'] ?? null,
                'expiration_date' => $line['expiration_date'] ?? null];
        }, array_values($data['items']));
        usort($assignments, fn ($a, $b) => $a['item_id'] <=> $b['item_id']);
        $key = strtolower($data['key']);
        $hash = $this->hash([$actor->id, $order->id, (int) $data['version'], $assignments]);

        return DB::transaction(function () use ($actor, $order, $data, $assignments, $key, $hash) {
            $this->context->lock($actor, ['purchases.receive', 'inventory.post']);
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->receipt_key === $key) {
                if (! hash_equals($order->receipt_hash, $hash)) {
                    $this->invalid('key', 'key_conflict');
                }

                return $this->withItems($order);
            }
            $this->draft($order, (int) $data['version']);
            $supplier = Supplier::whereKey($order->supplier_id)->lockForUpdate()->firstOrFail();
            if (! $supplier->is_active) {
                $this->invalid('supplier_id', 'inactive');
            }
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $ids = array_column($assignments, 'item_id');
            if (count($ids) !== $items->count() || array_diff($ids, $items->keys()->all()) !== []) {
                $this->invalid('items', 'assignments');
            }
            $products = Product::whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stockLines = [];
            foreach ($assignments as $index => $assignment) {
                if ($assignment['production_date'] && $assignment['expiration_date'] && $assignment['expiration_date'] < $assignment['production_date']) {
                    $this->invalid('items.'.$index.'.expiration_date', 'batch_dates');
                }
                $item = $items->get($assignment['item_id']);
                $product = $products->get($item->product_id);
                if (! $product?->is_active) {
                    $this->invalid('items', 'inactive');
                }
                if ($item->conversion_factor !== ($item->unit === 'carton' ? $product->pieces_per_carton : 1)) {
                    $this->invalid('items', 'packing');
                }
                if ($assignment['batch_id']) {
                    if ($assignment['batch_number'] !== null || $assignment['production_date'] || $assignment['expiration_date']) {
                        $this->invalid('items.'.$index.'.batch_id', 'batch_choice');
                    }
                    $batch = ProductBatch::whereKey($assignment['batch_id'])->lockForUpdate()->first();
                } else {
                    if ($assignment['batch_number'] === null) {
                        $this->invalid('items.'.$index.'.batch_number', 'batch_choice');
                    }
                    $identity = ['product_id' => $product->id, 'batch_number' => $assignment['batch_number']];
                    $batch = ProductBatch::where($identity)->lockForUpdate()->first()
                        ?? ProductBatch::create([...$identity, 'production_date' => $assignment['production_date'], 'expiration_date' => $assignment['expiration_date']]);
                    if ($batch->production_date?->toDateString() !== $assignment['production_date']
                        || $batch->expiration_date?->toDateString() !== $assignment['expiration_date']) {
                        $this->invalid('items.'.$index.'.batch_number', 'batch_dates');
                    }
                }
                if (! $batch || $batch->product_id !== $product->id) {
                    $this->invalid('items.'.$index.'.batch_id', 'batch_product');
                }
                $item->batch_id = $batch->id;
                $item->save();
                $stockLines[] = ['batch_id' => $batch->id, 'quantity' => $item->quantity, 'unit' => $item->unit];
            }
            $operation = app(StockLedger::class)->receive($actor, $key, 'PO:'.$order->id, $stockLines);
            $order->fill(['status' => 'received', 'version' => $order->version + 1, 'stock_operation_id' => $operation->id,
                'receipt_key' => $key, 'receipt_hash' => $hash, 'received_by' => $actor->id, 'received_at' => $operation->posted_at])->save();

            return $this->withItems($order);
        }, 3);
    }

    private function draft(PurchaseOrder $order, int $version): void
    {
        if ($order->status !== 'draft') {
            $this->invalid('order', 'not_draft');
        }
        if ($order->version !== $version) {
            $this->invalid('version', 'stale');
        }
    }

    private function withItems(PurchaseOrder $order): PurchaseOrder
    {
        return $order->setRelation('items', $order->items()->lockForUpdate()->get());
    }

    private function micros(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price), 2, '');

        return (int) $whole * 1000000 + (int) str_pad($fraction, 6, '0');
    }

    private function decimal(int $micros): string
    {
        return intdiv($micros, 1000000).'.'.str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('purchases.errors.'.$message)]);
    }
}
