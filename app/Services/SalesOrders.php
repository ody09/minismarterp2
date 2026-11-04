<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockOperation;
use App\Models\User;
use App\Support\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesOrders
{
    public function __construct(private StockContext $context, private CustomerPricing $pricing) {}

    public function save(User $actor, array $input, ?SalesOrder $order = null): SalesOrder
    {
        $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();
        $data = Validator::make($input, [
            'key' => [$order ? 'nullable' : 'required', 'uuid'], 'version' => [$order ? 'required' : 'nullable', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'], 'order_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'], 'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.unit' => ['required', 'string', Rule::in(['piece', 'carton'])],
        ])->validate();
        $lines = array_map(fn ($line) => ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'unit' => $line['unit']], array_values($data['items']));
        $pairs = array_map(fn ($line) => $line['product_id'].':'.$line['unit'], $lines);
        if (count(array_unique($pairs)) !== count($pairs)) {
            $this->invalid('items', 'duplicate');
        }
        $notes = trim($data['notes'] ?? '');
        $values = ['customer_id' => (int) $data['customer_id'], 'order_date' => $data['order_date'], 'notes' => $notes === '' ? null : $notes];
        $hash = $this->hash([$actor->id, $values, $lines]);

        return DB::transaction(function () use ($actor, $order, $data, $lines, $values, $hash) {
            $this->context->lock($actor, ['sales.manage']);
            if (! $order) {
                $key = strtolower($data['key']);
                if ($existing = SalesOrder::where('creation_key', $key)->lockForUpdate()->first()) {
                    if (! hash_equals($existing->creation_hash, $hash)) {
                        $this->invalid('key', 'key_conflict');
                    }

                    return $this->withItems($existing);
                }
            } else {
                $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->draft($order, (int) $data['version']);
            }
            $customer = Customer::whereKey($values['customer_id'])->lockForUpdate()->first();
            if (! $customer?->is_active) {
                $this->invalid('customer_id', 'inactive');
            }
            $products = Product::whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($lines as $index => &$line) {
                $product = $products->get($line['product_id']);
                if (! $product?->is_active) {
                    $this->invalid('items.'.$index.'.product_id', 'inactive');
                }
                $price = $this->pricing->resolve($customer, $product, $line['quantity'], $line['unit'], $values['order_date']);
                $subtotal = DecimalAmount::subtotal($price['price'], $line['quantity']);
                $total = DecimalAmount::add($total, $subtotal);
                $line += ['line_number' => $index + 1, 'product_name' => $product->name,
                    'conversion_factor' => $line['unit'] === 'carton' ? $product->pieces_per_carton : 1,
                    'unit_price' => DecimalAmount::format($price['price']), 'subtotal' => DecimalAmount::format($subtotal),
                    'price_source' => $price['source'], 'customer_price_id' => $price['rule_id'], 'price_rule_version' => $price['rule_version']];
            }
            unset($line);
            $values += ['customer_name' => $customer->name, 'total_amount' => DecimalAmount::format($total)];
            if ($order) {
                $order->fill([...$values, 'version' => $order->version + 1])->save();
                $order->items()->delete();
            } else {
                $order = SalesOrder::create([...$values, 'user_id' => $actor->id, 'status' => 'draft', 'version' => 1,
                    'creation_key' => $key, 'creation_hash' => $hash]);
            }
            $order->items()->createMany($lines);

            return $this->withItems($order);
        }, 3);
    }

    public function cancel(User $actor, SalesOrder $order, int $version): SalesOrder
    {
        return DB::transaction(function () use ($actor, $order, $version) {
            $this->context->lock($actor, ['sales.manage']);
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->draft($order, $version);
            $order->fill(['status' => 'cancelled', 'version' => $order->version + 1, 'cancelled_by' => $actor->id,
                'cancelled_at' => CarbonImmutable::now('UTC')])->save();

            return $this->withItems($order);
        }, 3);
    }

    public function confirm(User $actor, SalesOrder $order, array $input): SalesOrder
    {
        $data = Validator::make($input, ['key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']])->validate();
        $key = strtolower($data['key']);
        $hash = $this->hash([$actor->id, $order->id, (int) $data['version']]);

        return DB::transaction(function () use ($actor, $order, $data, $key, $hash) {
            $this->context->lock($actor, ['sales.post', 'inventory.post']);
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->confirmation_key === $key) {
                if (! hash_equals($order->confirmation_hash, $hash)) {
                    $this->invalid('key', 'key_conflict');
                }

                return $this->withItems($order);
            }
            $this->draft($order, (int) $data['version']);
            $customer = Customer::whereKey($order->customer_id)->lockForUpdate()->firstOrFail();
            if (! $customer->is_active) {
                $this->invalid('customer_id', 'inactive');
            }
            $items = $order->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                $this->invalid('items', 'empty');
            }
            $products = Product::whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product?->is_active) {
                    $this->invalid('items', 'inactive');
                }
                if ($item->conversion_factor !== ($item->unit === 'carton' ? $product->pieces_per_carton : 1)) {
                    $this->invalid('items', 'packing');
                }
                $price = $this->pricing->resolve($customer, $product, $item->quantity, $item->unit, $order->order_date->toDateString());
                if (DecimalAmount::format($price['price']) !== $item->unit_price || $price['source'] !== $item->price_source
                    || $price['rule_id'] !== $item->customer_price_id || $price['rule_version'] !== $item->price_rule_version) {
                    $this->invalid('items', 'pricing_changed');
                }
                $lines[] = ['product_id' => $product->id, 'quantity' => $item->quantity, 'unit' => $item->unit];
            }
            $operation = app(StockLedger::class)->issue($actor, $key, 'SO:'.$order->id, $lines);
            $order->fill(['status' => 'confirmed', 'version' => $order->version + 1, 'stock_operation_id' => $operation->id,
                'confirmation_key' => $key, 'confirmation_hash' => $hash, 'confirmed_by' => $actor->id, 'confirmed_at' => $operation->posted_at])->save();

            return $this->withItems($order);
        }, 3);
    }

    private function draft(SalesOrder $order, int $version): void
    {
        if ($order->status !== 'draft') {
            $this->invalid('order', 'not_draft');
        }
        if ($order->version !== $version) {
            $this->invalid('version', 'stale');
        }
    }

    private function withItems(SalesOrder $order): SalesOrder
    {
        $order->setRelation('items', $order->items()->lockForUpdate()->get());
        if ($order->stock_operation_id) {
            $order->setRelation('stockOperation', StockOperation::whereKey($order->stock_operation_id)->lockForUpdate()->firstOrFail());
        }

        return $order;
    }

    private function hash(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('sales.errors.'.$message)]);
    }
}
