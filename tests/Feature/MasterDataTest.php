<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    private function signIn(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role_id' => Role::where('code', $role)->firstOrFail()->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);

        return $user;
    }

    private function category(): int
    {
        $this->post('/categories', ['name' => 'Drinks', 'description' => 'Beverages', 'is_active' => '1'])->assertRedirect('/categories');

        return DB::table('categories')->where('name', 'Drinks')->value('id');
    }

    private function productData(int $categoryId): array
    {
        return ['name' => 'Orange juice', 'description' => 'Fresh product', 'category_id' => $categoryId,
            'pieces_per_carton' => 24, 'purchase_unit' => 'carton', 'selling_unit' => 'piece',
            'purchase_price' => '12.345678', 'selling_price' => '1.500000', 'reorder_level' => 10, 'is_active' => '1'];
    }

    private function product(): int
    {
        $this->post('/products', $this->productData($this->category()))->assertRedirect('/products');

        return DB::table('products')->where('name', 'Orange juice')->value('id');
    }

    public function test_categories_can_be_created_searched_edited_and_deleted_when_unused(): void
    {
        $this->signIn();
        $id = $this->category();
        $this->get('/categories?q=Drinks')->assertOk()->assertSee('Drinks');
        $this->get('/categories/create')->assertOk();
        $this->get('/categories/'.$id.'/edit')->assertOk();
        $this->patch('/categories/'.$id, ['name' => 'Beverages', 'description' => '', 'is_active' => '0'])->assertRedirect('/categories');
        $this->assertDatabaseHas('categories', ['id' => $id, 'name' => 'Beverages', 'is_active' => false]);
        $this->delete('/categories/'.$id)->assertRedirect('/categories');
        $this->assertDatabaseMissing('categories', ['id' => $id]);
    }

    public function test_duplicate_category_names_are_rejected(): void
    {
        $this->signIn();
        $this->category();
        $this->post('/categories', ['name' => 'Drinks', 'is_active' => '1'])->assertSessionHasErrors('name');
    }

    public function test_product_prices_units_and_activation_are_preserved(): void
    {
        $this->signIn();
        $id = $this->product();
        $product = Product::findOrFail($id);
        $this->assertSame('12.345678', $product->purchase_price);
        $this->assertSame('1.500000', $product->selling_price);
        $this->assertSame(72, $product->toPieces(3, 'carton'));
        $this->assertSame(3, $product->toPieces(3, 'piece'));
        $this->get('/products?q=Orange')->assertOk()->assertSee('Orange juice');
        $this->get('/products/create')->assertOk();
        $this->get('/products/'.$id.'/edit')->assertOk();
        $this->patch('/products/'.$id, [...$this->productData($product->category_id), 'is_active' => '0'])->assertRedirect('/products');
        $this->assertDatabaseHas('products', ['id' => $id, 'is_active' => false]);
        $this->delete('/products/'.$id)->assertRedirect('/products');
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_invalid_conversion_units_and_money_are_rejected(): void
    {
        $this->signIn();
        $categoryId = $this->category();
        $this->post('/products', [...$this->productData($categoryId), 'pieces_per_carton' => 0,
            'purchase_unit' => 'pallet', 'selling_price' => '1.1234567', 'purchase_price' => '-1', 'reorder_level' => -1])
            ->assertSessionHasErrors(['pieces_per_carton', 'purchase_unit', 'selling_price', 'purchase_price', 'reorder_level']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_categories_and_non_scalar_inputs_are_rejected(): void
    {
        $this->signIn();
        $this->post('/products', [...$this->productData(99999), 'name' => ['invalid']])->assertSessionHasErrors(['category_id', 'name']);
    }

    public function test_rejected_array_inputs_redisplay_each_form_without_crashing(): void
    {
        $this->signIn();
        $this->product();
        foreach ([
            ['post', '/categories', '/categories/create', ['name' => ['invalid'], 'description' => ['invalid'], 'is_active' => '1']],
            ['post', '/products', '/products/create', ['name' => ['invalid'], 'category_id' => ['invalid'], 'purchase_unit' => ['invalid'], 'purchase_price' => ['invalid'], 'pieces_per_carton' => ['invalid'], 'is_active' => '1']],
            ['post', '/customers', '/customers/create', ['name' => ['invalid'], 'address' => ['invalid'], 'is_active' => '1']],
            ['post', '/suppliers', '/suppliers/create', ['name' => ['invalid'], 'phone' => ['invalid'], 'is_active' => '1']],
            ['post', '/batches', '/batches/create', ['product_id' => ['invalid'], 'batch_number' => ['invalid'], 'production_date' => ['invalid']]],
            ['patch', '/warehouses', '/warehouses/edit', ['name' => ['invalid'], 'location' => ['invalid']]],
        ] as [$method, $endpoint, $form, $payload]) {
            $this->from($form)->followingRedirects()->{$method}($endpoint, $payload)->assertOk()->assertSee('role="alert"', false);
        }
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_batches', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_zero_is_a_real_product_and_batch_search_term(): void
    {
        $this->signIn();
        $productId = $this->product();
        $categoryId = DB::table('products')->where('id', $productId)->value('category_id');
        $this->post('/products', [...$this->productData($categoryId), 'name' => 'Product 0'])->assertRedirect('/products');
        $this->get('/products?q=0')->assertOk()->assertSee('Product 0')->assertDontSee('Orange juice');
        foreach (['LOT-0', 'LOT-1'] as $number) {
            $this->post('/batches', ['product_id' => $productId, 'batch_number' => $number])->assertRedirect('/batches');
        }
        $this->get('/batches?q=0')->assertOk()->assertSee('LOT-0')->assertDontSee('LOT-1');
    }

    public function test_main_warehouse_cannot_reuse_another_warehouse_name(): void
    {
        $this->signIn();
        Warehouse::create(['code' => 'other', 'name' => 'Other warehouse']);
        $this->patch('/warehouses', ['name' => 'Other warehouse'])->assertSessionHasErrors('name');
        $this->assertDatabaseHas('warehouses', ['code' => 'main', 'name' => 'Main warehouse']);
    }

    public function test_linked_categories_cannot_be_deleted(): void
    {
        $this->signIn();
        $productId = $this->product();
        $categoryId = DB::table('products')->where('id', $productId)->value('category_id');
        $this->delete('/categories/'.$categoryId)->assertSessionHasErrors('record');
        $this->assertDatabaseHas('categories', ['id' => $categoryId]);
    }

    public function test_batches_track_dates_without_creating_stock_and_protect_the_product(): void
    {
        $this->signIn();
        $productId = $this->product();
        $this->post('/batches', ['product_id' => $productId, 'batch_number' => 'LOT-001',
            'production_date' => '2026-10-01', 'expiration_date' => '2027-10-01'])->assertRedirect('/batches');
        $id = DB::table('product_batches')->where('batch_number', 'LOT-001')->value('id');
        $this->get('/batches?product_id='.$productId.'&q=LOT')->assertOk()->assertSee('LOT-001');
        $this->get('/batches/create')->assertOk();
        $this->get('/batches/'.$id.'/edit')->assertOk();
        $this->assertFalse(Schema::hasColumn('product_batches', 'quantity'));
        $this->delete('/products/'.$productId)->assertSessionHasErrors('record');
        $this->delete('/batches/'.$id)->assertRedirect('/batches');
        $this->assertDatabaseMissing('product_batches', ['id' => $id]);
    }

    public function test_batch_numbers_are_unique_per_product_and_dates_are_validated(): void
    {
        $this->signIn();
        $productId = $this->product();
        $data = ['product_id' => $productId, 'batch_number' => 'LOT-1', 'production_date' => null, 'expiration_date' => '2027-01-01'];
        $this->post('/batches', $data)->assertRedirect('/batches');
        $this->post('/batches', $data)->assertSessionHasErrors('batch_number');
        $this->post('/batches', [...$data, 'batch_number' => 'LOT-2', 'production_date' => '2028-01-01'])->assertSessionHasErrors('expiration_date');
        $this->post('/batches', [...$data, 'batch_number' => 'LOT-3', 'expiration_date' => '2027-02-30'])->assertSessionHasErrors('expiration_date');
        $this->post('/batches', [...$data, 'product_id' => ['invalid']])->assertSessionHasErrors('product_id');
        $second = [...$this->productData(DB::table('products')->where('id', $productId)->value('category_id')), 'name' => 'Water'];
        $this->post('/products', $second)->assertRedirect('/products');
        $otherId = DB::table('products')->where('name', 'Water')->value('id');
        $this->post('/batches', [...$data, 'product_id' => $otherId])->assertRedirect('/batches');
        $batchId = DB::table('product_batches')->where('product_id', $productId)->value('id');
        $this->patch('/batches/'.$batchId, [...$data, 'product_id' => $otherId, 'batch_number' => 'MOVED'])->assertSessionHasErrors('product_id');
    }

    public function test_customers_and_suppliers_have_independent_crud_and_search(): void
    {
        $this->signIn();
        foreach (['customers', 'suppliers'] as $resource) {
            $this->post('/'.$resource, ['name' => 'Contact', 'phone' => '777123456', 'email' => 'contact@example.test', 'address' => 'Sanaa', 'is_active' => '1'])->assertRedirect('/'.$resource);
            $id = DB::table($resource)->where('name', 'Contact')->value('id');
            $this->get('/'.$resource.'?q=777123456')->assertOk()->assertSee('Contact');
            $this->get('/'.$resource.'/create')->assertOk();
            $this->get('/'.$resource.'/'.$id.'/edit')->assertOk();
            $this->patch('/'.$resource.'/'.$id, ['name' => 'Updated', 'phone' => '', 'email' => '', 'address' => '', 'is_active' => '0'])->assertRedirect('/'.$resource);
            $this->assertDatabaseHas($resource, ['id' => $id, 'name' => 'Updated', 'is_active' => false]);
            $this->delete('/'.$resource.'/'.$id)->assertRedirect('/'.$resource);
        }
    }

    public function test_contact_validation_rejects_invalid_email_and_missing_name(): void
    {
        $this->signIn();
        foreach (['customers', 'suppliers'] as $resource) {
            $this->post('/'.$resource, ['name' => '', 'email' => 'invalid', 'is_active' => '1'])->assertSessionHasErrors(['name', 'email']);
        }
    }

    public function test_new_modules_deny_unauthorized_read_and_forged_writes(): void
    {
        $this->signIn('sales');
        foreach (['categories', 'products', 'batches', 'customers', 'suppliers'] as $resource) {
            $this->get('/'.$resource)->assertForbidden();
            $this->get('/'.$resource.'/create')->assertForbidden();
            $this->post('/'.$resource, [])->assertForbidden();
        }
        $this->get('/warehouses')->assertForbidden();
        $this->get('/warehouses/edit')->assertForbidden();
        $this->patch('/warehouses', [])->assertForbidden();
    }

    public function test_read_only_permissions_do_not_authorize_updates(): void
    {
        $this->signIn();
        $id = $this->category();
        $role = Role::factory()->create();
        $role->permissions()->sync(Permission::where('code', 'categories.view')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->get('/categories')->assertOk()->assertDontSee('/categories/create', false);
        $this->patch('/categories/'.$id, ['name' => 'Forged', 'is_active' => '1'])->assertForbidden();
        $this->delete('/categories/'.$id)->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $id, 'name' => 'Drinks']);
    }

    public function test_arabic_and_english_labels_are_available_for_the_new_screens(): void
    {
        $this->signIn();
        $this->withSession(['locale' => 'ar'])->get('/products')->assertOk()->assertSee('المنتجات');
        $this->withSession(['locale' => 'en'])->get('/products')->assertOk()->assertSee('Products');
    }

    public function test_permission_labels_are_translated_on_roles_and_overview_pages(): void
    {
        $this->signIn();
        $this->withSession(['locale' => 'ar'])->get('/roles')->assertOk()->assertSee('إدارة المنتجات')->assertDontSee('app.permission_labels.', false);
        $this->get('/dashboard')->assertOk()->assertSee('عرض النظرة العامة')->assertDontSee('app.permission_labels.', false);
        $this->withSession(['locale' => 'en'])->get('/roles')->assertOk()->assertSee('Manage products')->assertDontSee('app.permission_labels.', false);
        $this->get('/dashboard')->assertOk()->assertSee('View overview')->assertDontSee('app.permission_labels.', false);
    }

    public function test_admin_can_grant_more_than_the_old_five_permissions(): void
    {
        $this->signIn();
        $this->post('/roles', ['name' => 'Master manager', 'code' => 'master-manager',
            'permissions' => ['dashboard.view', 'categories.view', 'categories.manage', 'products.view', 'products.manage', 'batches.view', 'batches.manage']])->assertRedirect('/roles');
        $role = Role::where('code', 'master-manager')->firstOrFail();
        $this->assertSame(7, $role->permissions()->count());
    }

    public function test_single_main_warehouse_can_be_edited_without_a_creation_or_transfer_flow(): void
    {
        $this->signIn();
        $this->get('/warehouses')->assertOk();
        $this->get('/warehouses/edit')->assertOk();
        $this->patch('/warehouses', ['name' => 'Main Store', 'location' => 'Sanaa', 'description' => 'Primary'])->assertRedirect('/warehouses');
        $this->assertDatabaseHas('warehouses', ['code' => 'main', 'name' => 'Main Store', 'location' => 'Sanaa']);
        $this->get('/warehouses/create')->assertNotFound();
        $this->post('/warehouses', ['name' => 'Second'])->assertStatus(405);
        $this->seed(MasterDataSeeder::class);
        $this->assertDatabaseCount('warehouses', 1);
        $this->assertDatabaseHas('warehouses', ['name' => 'Main Store']);
    }
}
