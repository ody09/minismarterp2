@php
    $value = fn($field, $default = '') => is_scalar($line[$field] ?? $default) ? ($line[$field] ?? $default) : '';
@endphp
<tr data-purchase-line>
    <td><label for="product_{{ $index }}"><span class="sr-only">{{ __('catalog.products') }}</span><select id="product_{{ $index }}" name="items[{{ $index }}][product_id]" data-field="product_id" required><option value="">{{ __('purchases.select_product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}" data-unit="{{ $product->purchase_unit }}" data-price="{{ $product->purchase_price }}" @selected((string)$value('product_id') === (string)$product->id)>{{ $product->name }}{{ $product->is_active ? '' : ' · '.__('app.inactive') }}</option>@endforeach</select></label></td>
    <td><label for="quantity_{{ $index }}"><span class="sr-only">{{ __('purchases.quantity') }}</span><input id="quantity_{{ $index }}" name="items[{{ $index }}][quantity]" data-field="quantity" type="number" min="1" max="2147483647" step="1" required value="{{ $value('quantity', 1) }}"></label></td>
    <td><label for="unit_{{ $index }}"><span class="sr-only">{{ __('purchases.unit') }}</span><select id="unit_{{ $index }}" name="items[{{ $index }}][unit]" data-field="unit" required>@foreach(['piece', 'carton'] as $unit)<option value="{{ $unit }}" @selected($value('unit', 'carton') === $unit)>{{ __('catalog.unit_'.$unit) }}</option>@endforeach</select></label></td>
    <td><label for="unit_price_{{ $index }}"><span class="sr-only">{{ __('purchases.unit_price') }}</span><input id="unit_price_{{ $index }}" name="items[{{ $index }}][unit_price]" data-field="unit_price" type="number" min="0" max="999999999.999999" step="0.000001" dir="ltr" required value="{{ $value('unit_price', '0.000000') }}"></label></td>
    <td><button class="button small danger" type="button" data-remove-line>{{ __('purchases.remove_line') }}</button></td>
</tr>
