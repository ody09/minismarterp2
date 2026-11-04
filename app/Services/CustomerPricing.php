<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\User;
use App\Support\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerPricing
{
    public function __construct(private StockContext $context) {}

    public function save(User $actor, array $input, ?CustomerProductPrice $price = null): CustomerProductPrice
    {
        $data = Validator::make($input, ['customer_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'], 'unit' => ['required', 'string', Rule::in(['piece', 'carton'])],
            'min_quantity' => ['required', 'integer', 'min:1', 'max:2147483647'], 'unit_price' => ['required'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['required', 'boolean'], 'version' => [$price ? 'required' : 'nullable', 'integer', 'min:1']])->validate();
        $amount = DecimalAmount::price($data['unit_price']);
        $from = ($data['valid_from'] ?? '') ?: null;
        $to = ($data['valid_to'] ?? '') ?: null;
        if ($from && $to && $from > $to) {
            $this->invalid('valid_to', 'dates');
        }
        $values = ['customer_id' => (int) $data['customer_id'], 'product_id' => (int) $data['product_id'],
            'unit' => $data['unit'], 'min_quantity' => (int) $data['min_quantity'], 'unit_price' => DecimalAmount::format($amount),
            'valid_from' => $from, 'valid_to' => $to, 'is_active' => (bool) $data['is_active']];

        return DB::transaction(function () use ($actor, $price, $data, $values) {
            $this->context->lock($actor, ['prices.manage']);
            if ($price) {
                $price = CustomerProductPrice::whereKey($price->id)->lockForUpdate()->firstOrFail();
                if ($price->version !== (int) $data['version']) {
                    $this->invalid('version', 'stale');
                }
            }
            $customer = Customer::whereKey($values['customer_id'])->lockForUpdate()->first();
            $product = Product::whereKey($values['product_id'])->lockForUpdate()->first();
            if (! $customer || ! $product || ($values['is_active'] && (! $customer->is_active || ! $product->is_active))) {
                $this->invalid('record', 'inactive');
            }
            if ($values['is_active']) {
                $overlap = CustomerProductPrice::where('customer_id', $customer->id)->where('product_id', $product->id)
                    ->where('unit', $values['unit'])->where('min_quantity', $values['min_quantity'])->where('is_active', true)
                    ->when($price, fn ($query) => $query->where('id', '!=', $price->id))
                    ->when($values['valid_from'], fn ($query, $from) => $query->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from)))
                    ->when($values['valid_to'], fn ($query, $to) => $query->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $to)))
                    ->lockForUpdate()->first(['id']);
                if ($overlap) {
                    $this->invalid('valid_from', 'overlap');
                }
            }
            if ($price) {
                $price->fill([...$values, 'version' => $price->version + 1])->save();
            } else {
                $price = CustomerProductPrice::create([...$values, 'version' => 1]);
            }

            return $price;
        }, 3);
    }

    // Caller holds StockContext and current customer/product locks in the sale transaction.
    public function resolve(Customer $customer, Product $product, int $quantity, string $unit, string $date): array
    {
        $rule = CustomerProductPrice::where('customer_id', $customer->id)->where('product_id', $product->id)
            ->where('unit', $unit)->where('is_active', true)->where('min_quantity', '<=', $quantity)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('min_quantity')->orderByDesc('id')->lockForUpdate()->first();
        if ($rule) {
            return ['price' => DecimalAmount::price($rule->unit_price), 'source' => 'customer', 'rule_id' => $rule->id, 'rule_version' => $rule->version];
        }
        $amount = DecimalAmount::price($product->selling_price);
        if ($product->selling_unit !== $unit) {
            $factor = $product->pieces_per_carton;
            if ($factor < 1 || ($unit === 'carton' && $amount > intdiv(DecimalAmount::MAX_PRICE, $factor))) {
                $this->invalid('items', 'conversion');
            }
            $amount = $unit === 'carton' ? $amount * $factor
                : intdiv($amount, $factor) + (($amount % $factor) * 2 >= $factor ? 1 : 0);
        }

        return ['price' => $amount, 'source' => 'catalog', 'rule_id' => null, 'rule_version' => null];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('prices.errors.'.$message)]);
    }
}
