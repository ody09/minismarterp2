<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Services\CustomerPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerPriceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'], 'active' => ['nullable', 'string', Rule::in(['0', '1'])]]);
        $query = CustomerProductPrice::with(['customer', 'product']);
        foreach (['customer_id', 'product_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['active'] ?? '') !== '') {
            $query->where('is_active', $filters['active']);
        }

        return view('prices.index', ['prices' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $filters, 'customers' => Customer::orderBy('name')->get(), 'products' => Product::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return $this->form(new CustomerProductPrice);
    }

    public function edit(CustomerProductPrice $price): View
    {
        return $this->form($price);
    }

    public function store(Request $request, CustomerPricing $pricing): RedirectResponse
    {
        $pricing->save($request->user(), $request->all());

        return $this->destination($request);
    }

    public function update(Request $request, CustomerProductPrice $price, CustomerPricing $pricing): RedirectResponse
    {
        $pricing->save($request->user(), $request->all(), $price);

        return $this->destination($request);
    }

    private function form(CustomerProductPrice $price): View
    {
        return view('prices.form', ['price' => $price, 'customers' => Customer::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get()]);
    }

    private function destination(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->can('prices.view') ? 'prices.index' : 'prices.create')->with('status', __('app.saved'));
    }
}
