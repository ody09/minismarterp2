<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait BuildsSalesData
{
    protected User $actor;

    protected Customer $customer;

    protected Product $product;

    protected function buildSalesData(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-03 09:00:00 UTC');
        $this->seed(DatabaseSeeder::class);
        $this->actor = User::factory()->create(['role_id' => Role::where('code', 'admin')->firstOrFail()->id])->refresh();
        $this->customer = Customer::create(['name' => 'Sales customer', 'is_active' => true]);
        $category = Category::create(['name' => 'Sales category', 'is_active' => true]);
        $this->product = Product::create(['name' => 'Sales product', 'category_id' => $category->id,
            'pieces_per_carton' => 24, 'purchase_unit' => 'carton', 'selling_unit' => 'piece',
            'purchase_price' => '24', 'selling_price' => '2.500001', 'reorder_level' => 0, 'is_active' => true]);
    }

    protected function priceData(array $changes = []): array
    {
        return array_replace(['customer_id' => $this->customer->id, 'product_id' => $this->product->id,
            'unit' => 'piece', 'min_quantity' => 1, 'unit_price' => '1.250001',
            'valid_from' => null, 'valid_to' => null, 'is_active' => true], $changes);
    }

    protected function saleData(array $changes = []): array
    {
        return array_replace(['key' => (string) Str::uuid(), 'customer_id' => $this->customer->id,
            'order_date' => '2026-11-03', 'notes' => 'Sale notes', 'items' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit' => 'piece']]], $changes);
    }

    protected function assertSaleInvalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid commercial operation must fail.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
