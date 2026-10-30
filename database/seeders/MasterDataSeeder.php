<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        // Seed only the configured operational warehouse; preserve any owner edits.
        Warehouse::firstOrCreate(['code' => 'main'], ['name' => 'Main warehouse']);
    }
}
