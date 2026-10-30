<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductBatchRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\MasterDataDeletion;
use App\Services\StockMetadata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductBatchController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'product_id' => ['nullable', 'integer', 'exists:products,id']]);
        $batches = ProductBatch::with('product')
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where('batch_number', 'like', '%'.$filters['q'].'%'))
            ->when(! empty($filters['product_id']), fn ($query) => $query->where('product_id', $filters['product_id']))
            ->orderByDesc('id')->paginate(15)->withQueryString();

        return view('batches.index', ['batches' => $batches, 'filters' => $filters, 'products' => Product::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('batches.form', ['batch' => new ProductBatch, 'products' => Product::orderBy('name')->get()]);
    }

    public function store(ProductBatchRequest $request): RedirectResponse
    {
        ProductBatch::create($request->validated());

        return redirect()->route($request->user()->can('batches.view') ? 'batches.index' : 'batches.create')->with('status', __('app.saved'));
    }

    public function edit(ProductBatch $batch): View
    {
        return view('batches.form', ['batch' => $batch, 'products' => Product::orderBy('name')->get()]);
    }

    public function update(ProductBatchRequest $request, ProductBatch $batch, StockMetadata $metadata): RedirectResponse
    {
        $metadata->updateBatch($batch, $request->validated());

        return redirect()->route($request->user()->can('batches.view') ? 'batches.index' : 'batches.create')->with('status', __('app.saved'));
    }

    public function destroy(Request $request, ProductBatch $batch, MasterDataDeletion $deletion): RedirectResponse
    {
        $deletion->delete($batch);

        return redirect()->route($request->user()->can('batches.view') ? 'batches.index' : 'batches.create')->with('status', __('catalog.deleted'));
    }
}
