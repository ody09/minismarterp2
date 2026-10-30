<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\MasterDataDeletion;
use App\Services\StockMetadata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer', 'exists:categories,id']]);
        $products = Product::with('category')->withCount('batches')
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when(! empty($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('products.index', ['products' => $products, 'filters' => $filters, 'categories' => Category::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('products.form', ['product' => new Product, 'categories' => Category::orderBy('name')->get()]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Product::create($request->validated());

        return redirect()->route($request->user()->can('products.view') ? 'products.index' : 'products.create')->with('status', __('app.saved'));
    }

    public function edit(Product $product): View
    {
        return view('products.form', ['product' => $product, 'categories' => Category::orderBy('name')->get()]);
    }

    public function update(ProductRequest $request, Product $product, StockMetadata $metadata): RedirectResponse
    {
        $metadata->updateProduct($product, $request->validated());

        return redirect()->route($request->user()->can('products.view') ? 'products.index' : 'products.create')->with('status', __('app.saved'));
    }

    public function destroy(Request $request, Product $product, MasterDataDeletion $deletion): RedirectResponse
    {
        $deletion->delete($product);

        return redirect()->route($request->user()->can('products.view') ? 'products.index' : 'products.create')->with('status', __('catalog.deleted'));
    }
}
