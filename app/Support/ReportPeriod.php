<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class ReportPeriod
{
    public readonly string $from;

    public readonly string $to;

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $until;

    public readonly array $filters;

    public function __construct(array $input, ?string $partyField = null)
    {
        $today = CarbonImmutable::now(config('inventory.timezone'));
        $payload = ['from' => ($input['from'] ?? '') === '' ? $today->startOfMonth()->toDateString() : $input['from'],
            'to' => ($input['to'] ?? '') === '' ? $today->toDateString() : $input['to']];
        $rules = ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']];
        if ($partyField) {
            $payload[$partyField] = $input[$partyField] ?? null;
            $rules[$partyField] = ['nullable', 'integer', 'min:1', 'exists:'.($partyField === 'customer_id' ? 'customers' : 'suppliers').',id'];
        }
        $this->filters = Validator::make($payload, $rules)->validate();
        $this->from = $this->filters['from'];
        $this->to = $this->filters['to'];
        $this->start = CarbonImmutable::parse($this->from, config('inventory.timezone'))->startOfDay()->utc();
        $this->until = CarbonImmutable::parse($this->to, config('inventory.timezone'))->addDay()->startOfDay()->utc();
    }
}
