<div class="stats-grid report-stock-grid">
<section class="card stat"><span class="muted">{{ __('reports.products') }}</span><strong>{{ $summary['products'] }}</strong></section>
@foreach(['low' => 'low', 'near_batches' => 'near', 'expired_batches' => 'expired'] as $metric => $alert)<a class="card stat" href="{{ route('reports.inventory', ['alert' => $alert, 'expiry_days' => $filters['expiry_days'], 'q' => $filters['q'] ?? null, 'product_id' => $filters['product_id'] ?? null]) }}"><span class="muted">{{ __('reports.'.$metric) }}</span><strong>{{ $summary[$metric] }}</strong></a>@endforeach
</div>
