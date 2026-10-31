<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrders;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'received', 'cancelled'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])]]);
        $query = PurchaseOrder::query()->with('user');
        if (($filters['q'] ?? '') !== '') {
            $number = preg_replace('/\APO-0*/i', '', $filters['q']);
            $query->where(fn ($q) => $q->where('supplier_name', 'like', '%'.$filters['q'].'%')
                ->orWhere('id', ctype_digit($number) ? $number : 0));
        }
        foreach (['supplier_id', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('order_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('order_date', '<=', $filters['to']);
        }

        return view('purchases.index', ['orders' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $filters, 'suppliers' => Supplier::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return $this->form(new PurchaseOrder);
    }

    public function store(Request $request, PurchaseOrders $orders): RedirectResponse
    {
        $order = $orders->save($request->user(), $request->all());

        return $this->destination($request, $order)->with('status', __('app.saved'));
    }

    public function show(PurchaseOrder $purchase): View
    {
        return view('purchases.show', ['order' => $purchase->load(['items.batch', 'user', 'receiver', 'canceller', 'stockOperation'])]);
    }

    public function edit(Request $request, PurchaseOrder $purchase): View|RedirectResponse
    {
        return $purchase->status === 'draft' ? $this->form($purchase) : $this->destination($request, $purchase);
    }

    public function update(Request $request, PurchaseOrder $purchase, PurchaseOrders $orders): RedirectResponse
    {
        $order = $orders->save($request->user(), $request->all(), $purchase);

        return $this->destination($request, $order)->with('status', __('app.saved'));
    }

    public function cancel(Request $request, PurchaseOrder $purchase, PurchaseOrders $orders): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $order = $orders->cancel($request->user(), $purchase, (int) $data['version']);

        return $this->destination($request, $order)->with('status', __('purchases.cancelled_notice'));
    }

    public function receipt(Request $request, PurchaseOrder $purchase): View|RedirectResponse
    {
        if ($purchase->status !== 'draft') {
            return $this->destination($request, $purchase);
        }
        $purchase->load('items');
        $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();
        $batches = ProductBatch::whereIn('product_id', $purchase->items->pluck('product_id'))
            ->where(fn ($query) => $query->whereNull('expiration_date')->orWhere('expiration_date', '>=', $today))
            ->orderBy('batch_number')->get()->groupBy('product_id');

        return view('purchases.receive', ['order' => $purchase, 'batches' => $batches, 'key' => (string) Str::uuid()]);
    }

    public function receive(Request $request, PurchaseOrder $purchase, PurchaseOrders $orders): RedirectResponse
    {
        $order = $orders->receive($request->user(), $purchase, $request->all());

        return $this->destination($request, $order)->with('status', __('purchases.received_notice'));
    }

    private function form(PurchaseOrder $order): View
    {
        $order->load('items');

        return view('purchases.form', ['order' => $order, 'suppliers' => Supplier::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(), 'key' => (string) Str::uuid(),
            'today' => CarbonImmutable::now(config('inventory.timezone'))->toDateString()]);
    }

    private function destination(Request $request, PurchaseOrder $order): RedirectResponse
    {
        return redirect()->route($request->user()->can('purchases.view') ? 'purchases.show'
            : ($request->user()->can('purchases.manage') ? 'purchases.create' : $request->user()->landingRouteName()),
            $request->user()->can('purchases.view') ? ['purchase' => $order] : []);
    }
}
