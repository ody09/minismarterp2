<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\SalesOrders;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesOrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'confirmed', 'cancelled'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])]]);
        $query = SalesOrder::with('user');
        if (($filters['q'] ?? '') !== '') {
            $number = preg_replace('/\ASO-0*/i', '', $filters['q']);
            $query->where(fn ($q) => $q->where('customer_name', 'like', '%'.$filters['q'].'%')->orWhere('id', ctype_digit($number) ? $number : 0));
        }
        foreach (['customer_id', 'status'] as $field) {
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

        return view('sales.index', ['orders' => $query->orderByDesc('id')->paginate(15)->withQueryString(),
            'filters' => $filters, 'customers' => Customer::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return $this->form(new SalesOrder);
    }

    public function store(Request $request, SalesOrders $orders): RedirectResponse
    {
        return $this->destination($request, $orders->save($request->user(), $request->all()))->with('status', __('app.saved'));
    }

    public function edit(Request $request, SalesOrder $sale): View|RedirectResponse
    {
        return $sale->status === 'draft' ? $this->form($sale) : $this->destination($request, $sale);
    }

    public function update(Request $request, SalesOrder $sale, SalesOrders $orders): RedirectResponse
    {
        return $this->destination($request, $orders->save($request->user(), $request->all(), $sale))->with('status', __('app.saved'));
    }

    public function show(SalesOrder $sale): View
    {
        $sale->load(['items', 'user', 'confirmer', 'canceller', 'stockOperation']);
        $allocations = $sale->stockOperation?->transactions()->with('inventory.batch')->orderBy('line_number')->orderBy('allocation_number')->paginate(50);

        return view('sales.show', ['order' => $sale, 'allocations' => $allocations]);
    }

    public function review(Request $request, SalesOrder $sale): View|RedirectResponse
    {
        if ($sale->status !== 'draft') {
            return $this->destination($request, $sale);
        }

        return view('sales.confirm', ['order' => $sale->load('items'), 'key' => (string) Str::uuid()]);
    }

    public function confirm(Request $request, SalesOrder $sale, SalesOrders $orders): RedirectResponse
    {
        return $this->destination($request, $orders->confirm($request->user(), $sale, $request->all()))->with('status', __('sales.confirmed_notice'));
    }

    public function cancel(Request $request, SalesOrder $sale, SalesOrders $orders): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->destination($request, $orders->cancel($request->user(), $sale, (int) $data['version']))->with('status', __('sales.cancelled_notice'));
    }

    private function form(SalesOrder $order): View
    {
        return view('sales.form', ['order' => $order->load('items'), 'customers' => Customer::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(), 'today' => CarbonImmutable::now(config('inventory.timezone'))->toDateString(),
            'key' => (string) Str::uuid()]);
    }

    private function destination(Request $request, SalesOrder $order): RedirectResponse
    {
        return redirect()->route($request->user()->can('sales.view') ? 'sales.show'
            : ($request->user()->can('sales.manage') ? 'sales.create' : $request->user()->landingRouteName()),
            $request->user()->can('sales.view') ? ['sale' => $order] : []);
    }
}
