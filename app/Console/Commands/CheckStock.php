<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckStock extends Command
{
    protected $signature = 'erp:check-stock';

    protected $description = 'Read-only comparison of stock balances and movement totals';

    public function handle(): int
    {
        $totals = DB::table('inventory_transactions')->selectRaw('inventory_id, SUM(quantity) as movement_quantity')->groupBy('inventory_id');
        $mismatches = Inventory::leftJoinSub($totals, 'movement_totals', fn ($join) => $join->on('inventory.id', '=', 'movement_totals.inventory_id'))
            ->whereRaw('inventory.quantity <> COALESCE(movement_totals.movement_quantity, 0)')
            ->selectRaw('inventory.id, inventory.quantity, COALESCE(movement_totals.movement_quantity, 0) as movement_quantity')->get();
        if ($mismatches->isNotEmpty()) {
            $this->error('Stock mismatch detected. No quantities were changed.');
            $this->table(['Inventory ID', 'Balance', 'Ledger total'], $mismatches->map(fn ($row) => [$row->id, $row->quantity, $row->movement_quantity])->all());

            return self::FAILURE;
        }
        $this->info('Stock balances match the movement ledger. No quantities were changed.');

        return self::SUCCESS;
    }
}
