<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockOperation;
use App\Models\Warehouse;
use App\Services\StockBalances;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request, StockBalances $balances): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $products = $balances->products($filters)->orderBy('products.name')->paginate(15)->withQueryString();

        return view('inventory.index', compact('products', 'filters'));
    }

    public function product(Product $product, StockBalances $balances): View
    {
        $product = $balances->products()->whereKey($product->id)->firstOrFail();
        $warehouseId = Warehouse::where('code', 'main')->firstOrFail()->id;
        $batches = ProductBatch::where('product_id', $product->id)->leftJoin('inventory', function ($join) use ($warehouseId) {
            $join->on('product_batches.id', '=', 'inventory.batch_id')->where('inventory.warehouse_id', $warehouseId);
        })->select('product_batches.*', 'inventory.quantity as physical_quantity')->orderByRaw('expiration_date IS NULL')->orderBy('expiration_date')->orderBy('product_batches.id')->paginate(15);
        $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();

        return view('inventory.product', compact('product', 'batches', 'today'));
    }

    public function transactions(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'], 'type' => ['nullable', 'in:receipt,issue,reversal'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $query = InventoryTransaction::with(['inventory.batch.product', 'operation.user'])->whereHas('operation.warehouse', fn ($query) => $query->where('code', 'main'))
            ->when(! empty($filters['product_id']), fn ($query) => $query->whereHas('inventory.batch', fn ($query) => $query->where('product_id', $filters['product_id'])))
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->whereHas('operation', fn ($query) => $query->where('reference', 'like', '%'.$filters['q'].'%')))
            ->when(! empty($filters['type']), fn ($query) => $query->whereHas('operation', fn ($query) => $query->where('type', $filters['type'])));
        if (! empty($filters['from'])) {
            $from = CarbonImmutable::parse($filters['from'], config('inventory.timezone'))->startOfDay()->utc();
            $query->whereHas('operation', fn ($query) => $query->where('posted_at', '>=', $from));
        }
        if (! empty($filters['to'])) {
            $until = CarbonImmutable::parse($filters['to'], config('inventory.timezone'))->addDay()->startOfDay()->utc();
            $query->whereHas('operation', fn ($query) => $query->where('posted_at', '<', $until));
        }
        $transactions = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('inventory.transactions', ['transactions' => $transactions, 'filters' => $filters, 'products' => Product::orderBy('name')->get()]);
    }

    public function operation(StockOperation $operation): View
    {
        abort_unless($operation->warehouse->code === 'main', 404);
        $operation->load(['user', 'original', 'reversal']);
        $transactions = $operation->transactions()->with('inventory.batch.product')->orderBy('line_number')->orderBy('allocation_number')->paginate(20);

        return view('inventory.operation', compact('operation', 'transactions'));
    }
}
