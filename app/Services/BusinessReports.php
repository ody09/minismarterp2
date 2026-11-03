<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ReportPeriod;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BusinessReports
{
    public function financial(User $actor, string $kind, array $input): array
    {
        abort_unless(in_array($kind, ['sales', 'purchases'], true), 404);
        $this->authorize($actor, 'reports.'.$kind);
        $period = new ReportPeriod($input, $kind === 'sales' ? 'customer_id' : 'supplier_id');

        return DB::transaction(fn () => $this->financialData($kind, $period));
    }

    public function inventory(User $actor, array $input): array
    {
        $this->authorize($actor, 'reports.inventory');
        $filters = $this->stockFilters($input);

        return DB::transaction(fn () => $this->inventoryData($filters));
    }

    public function dashboard(User $actor, array $input): array
    {
        $fresh = $this->authorize($actor, 'dashboard.view');
        $period = new ReportPeriod($input);
        $filters = $this->stockFilters(['expiry_days' => $input['expiry_days'] ?? 30]);

        return DB::transaction(function () use ($fresh, $period, $filters) {
            $result = ['period' => $period, 'filters' => [...$period->filters, 'expiry_days' => $filters['expiry_days']]];
            foreach (['sales', 'purchases'] as $kind) {
                if ($fresh->can('reports.'.$kind)) {
                    $result[$kind] = $this->financialData($kind, $period, true);
                }
            }
            if ($fresh->can('reports.inventory')) {
                $result['inventory'] = $this->inventoryData($filters, true);
            }

            return $result;
        });
    }

    private function financialData(string $kind, ReportPeriod $period, bool $overview = false): array
    {
        $isSale = $kind === 'sales';
        $table = $isSale ? 'sales_orders' : 'purchase_orders';
        $items = $isSale ? 'sales_order_items' : 'purchase_order_items';
        $foreign = $isSale ? 'sales_order_id' : 'purchase_order_id';
        $posted = $isSale ? 'confirmed_at' : 'received_at';
        $party = $isSale ? 'customer_id' : 'supplier_id';
        $query = ($isSale ? SalesOrder::query() : PurchaseOrder::query())
            ->where('status', $isSale ? 'confirmed' : 'received')
            ->where($posted, '>=', $period->start)->where($posted, '<', $period->until);
        if (! empty($period->filters[$party])) {
            $query->where($party, $period->filters[$party]);
        }
        // Stream each header once; FEFO movement joins would duplicate invoice totals.
        $amount = BigDecimal::zero();
        $count = 0;
        foreach ((clone $query)->select(['id', 'total_amount'])->cursor() as $order) {
            $amount = $amount->plus($order->total_amount);
            $count++;
        }
        $pieces = BigInteger::zero();
        $lines = DB::table($items)->joinSub((clone $query)->select($table.'.id'), 'documents',
            fn ($join) => $join->on($items.'.'.$foreign, '=', 'documents.id'))->select([$items.'.quantity', $items.'.conversion_factor']);
        foreach ($lines->cursor() as $line) {
            $pieces = $pieces->plus(BigInteger::of((string) $line->quantity)->multipliedBy((string) $line->conversion_factor));
        }
        $rows = (clone $query)->with('user')->withSum('items as total_pieces', DB::raw('quantity * conversion_factor'))
            ->orderByDesc($posted)->orderByDesc('id');
        $orders = $overview ? $rows->limit(5)->get() : $rows->paginate(15)->withQueryString();

        return ['kind' => $kind, 'period' => $period, 'filters' => $period->filters,
            'summary' => ['count' => $count, 'amount' => (string) $amount->toScale(6), 'pieces' => (string) $pieces], 'orders' => $orders];
    }

    private function stockFilters(array $input): array
    {
        $filters = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', 'min:1', 'exists:products,id'],
            'alert' => ['nullable', 'string', Rule::in(['all', 'low', 'near', 'expired'])],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:365']])->validate();

        return [...$filters, 'alert' => ($filters['alert'] ?? '') === '' ? 'all' : $filters['alert'],
            'expiry_days' => (int) ((($filters['expiry_days'] ?? '') === '') ? 30 : $filters['expiry_days'])];
    }

    private function inventoryData(array $filters, bool $overview = false): array
    {
        $warehouse = Warehouse::where('code', 'main')->firstOrFail();
        $today = CarbonImmutable::now(config('inventory.timezone'))->toDateString();
        $until = CarbonImmutable::parse($today, config('inventory.timezone'))->addDays($filters['expiry_days'])->toDateString();
        $inner = app(StockBalances::class)->products($filters)
            ->when(! empty($filters['product_id']), fn ($query) => $query->whereKey($filters['product_id']))
            ->withSum(['inventory as near_quantity' => fn ($q) => $q->where('inventory.warehouse_id', $warehouse->id)
                ->where('inventory.quantity', '>', 0)->whereDate('product_batches.expiration_date', '>=', $today)
                ->whereDate('product_batches.expiration_date', '<=', $until)], 'quantity');
        $products = Product::query()->fromSub($inner, 'products')->select('products.*');
        $low = fn (Builder $query) => $query->where('products.is_active', true)
            ->whereRaw('COALESCE(products.sellable_quantity, 0) <= products.reorder_level');
        $batches = Inventory::join('product_batches', 'product_batches.id', '=', 'inventory.batch_id')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->where('inventory.warehouse_id', $warehouse->id)->where('inventory.quantity', '>', 0)
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where('products.name', 'like', '%'.$filters['q'].'%'))
            ->when(! empty($filters['product_id']), fn ($query) => $query->where('products.id', $filters['product_id']))
            ->select(['inventory.*', 'products.id as product_id', 'products.name as product_name', 'products.is_active as product_active',
                'product_batches.batch_number', 'product_batches.expiration_date'])
            ->withCasts(['expiration_date' => 'immutable_date', 'product_active' => 'boolean']);
        $near = fn ($query) => $query->whereDate('product_batches.expiration_date', '>=', $today)->whereDate('product_batches.expiration_date', '<=', $until);
        $summary = ['products' => (clone $products)->count(), 'low' => $low(clone $products)->count(),
            'near_batches' => $near(clone $batches)->count(),
            'expired_batches' => (clone $batches)->whereDate('product_batches.expiration_date', '<', $today)->count()];
        $productRows = clone $products;
        $batchRows = clone $batches;
        if ($overview || $filters['alert'] === 'low') {
            $low($productRows);
            $batchRows->whereIn('products.id', $low(clone $products)->select('products.id'));
        } elseif ($filters['alert'] === 'near') {
            $productRows->where('products.near_quantity', '>', 0);
            $near($batchRows);
        } elseif ($filters['alert'] === 'expired') {
            $productRows->where('products.expired_quantity', '>', 0);
            $batchRows->whereDate('product_batches.expiration_date', '<', $today);
        }
        if ($overview) {
            $batchRows = (clone $batches)->whereDate('product_batches.expiration_date', '<=', $until);
        }
        $productRows->orderBy('products.name')->orderBy('products.id');
        $batchRows->orderByRaw('product_batches.expiration_date IS NULL')->orderBy('product_batches.expiration_date')->orderBy('inventory.id');

        return ['filters' => $filters, 'today' => $today, 'until' => $until, 'summary' => $summary,
            'products' => $overview ? $productRows->limit(5)->get() : $productRows->paginate(15)->withQueryString(),
            'batches' => $overview ? $batchRows->limit(5)->get() : $batchRows->paginate(15, ['*'], 'batches_page')->withQueryString()];
    }

    private function authorize(User $actor, string $permission): User
    {
        $fresh = User::findOrFail($actor->id);
        if (! $fresh->is_active || $fresh->auth_version !== $actor->auth_version) {
            throw new AuthorizationException;
        }
        Gate::forUser($fresh)->authorize($permission);

        return $fresh;
    }
}
