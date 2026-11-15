<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\StockOperation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccessManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditFindingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::firstOrCreate(['code' => 'main'], ['name' => 'Main Warehouse']);
        foreach (Permission::CODES as $code) {
            Permission::firstOrCreate(['code' => $code]);
        }
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Administrator']);
        $adminRole->forceFill(['is_system' => true])->save();
        $adminRole->permissions()->sync(Permission::pluck('id'));

        $this->admin = new User;
        $this->admin->forceFill([
            'email' => 'admin@audit.local',
            'name' => 'Admin User',
            'password' => 'AdminPass123!Aa',
            'role_id' => $adminRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();
    }

    /**
     * PERF-01: Navigation sidebar query budget test.
     * The navigation sidebar must batch-fetch role permissions in exactly 1 query during view composition,
     * disaggregated from the page body (which executes 2 queries on /account for count and permission list).
     */
    public function test_navigation_sidebar_executes_single_batch_permission_query_budget(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        $sidebarPermissionQueries = 0;
        $bodyPermissionQueries = 0;
        DB::listen(function ($query) use (&$sidebarPermissionQueries, &$bodyPermissionQueries) {
            if (str_contains($query->sql, 'role_permissions')) {
                // Queries originating from partials.account-access.blade.php
                if (str_contains($query->sql, 'count(*)') || str_contains($query->sql, 'pivot_role_id')) {
                    $bodyPermissionQueries++;
                } else {
                    $sidebarPermissionQueries++;
                }
            }
        });

        $response = $this->get('/account');
        $response->assertOk();

        // Sidebar must execute exactly 1 batch query for permissions, not 15 or 27 individual queries
        $this->assertSame(1, $sidebarPermissionQueries, 'Expected exactly 1 batch permission query for the navigation sidebar (admin)');
        $this->assertSame(2, $bodyPermissionQueries, 'Account page body still executes its expected 2 permission queries');

        // Also verify budget for limited user
        $limitedRole = Role::firstOrCreate(['code' => 'budget_limited'], ['name' => 'Budget Limited']);
        $limitedRole->permissions()->sync(Permission::where('code', 'purchases.view')->pluck('id'));
        $limitedUser = new User;
        $limitedUser->forceFill([
            'email' => 'budget_limited@audit.local',
            'name' => 'Budget Limited User',
            'password' => 'LimitedPass123!Aa',
            'role_id' => $limitedRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $limitedSidebarQueries = 0;
        $limitedBodyQueries = 0;
        DB::listen(function ($query) use (&$limitedSidebarQueries, &$limitedBodyQueries) {
            if (str_contains($query->sql, 'role_permissions')) {
                if (str_contains($query->sql, 'count(*)') || str_contains($query->sql, 'pivot_role_id')) {
                    $limitedBodyQueries++;
                } else {
                    $limitedSidebarQueries++;
                }
            }
        });

        $this->actingAs($limitedUser);
        session(['auth_version' => 1]);
        $responseLimited = $this->get('/account');
        $responseLimited->assertOk();

        $this->assertSame(1, $limitedSidebarQueries, 'Expected exactly 1 batch permission query for navigation sidebar (limited user)');
        $this->assertSame(2, $limitedBodyQueries, 'Account page body still executes its expected 2 permission queries');
    }

    /**
     * PERF-01: Navigation sidebar renders expected links for admin user.
     */
    public function test_navigation_sidebar_renders_expected_links_for_admin(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        $response = $this->get('/account');
        $response->assertOk();

        // Brand link points to dashboard
        $response->assertSee(route('dashboard'), false);

        // Core navigation links
        $response->assertSee(route('dashboard'));
        $response->assertSee(route('users.index'));
        $response->assertSee(route('roles.index'));
        $response->assertSee(route('categories.index'));
        $response->assertSee(route('products.index'));
        $response->assertSee(route('batches.index'));
        $response->assertSee(route('customers.index'));
        $response->assertSee(route('suppliers.index'));
        $response->assertSee(route('warehouses.index'));
        $response->assertSee(route('inventory.index'));
        $response->assertSee(route('inventory.transactions'));
        $response->assertSee(route('purchases.index'));
        $response->assertSee(route('sales.index'));
        $response->assertSee(route('prices.index'));
        $response->assertSee(route('reports.index'));
    }

    /**
     * PERF-01: Navigation sidebar renders only authorized links for limited user.
     */
    public function test_navigation_sidebar_renders_only_authorized_links_for_limited_user(): void
    {
        $limitedRole = Role::firstOrCreate(['code' => 'purchasing_officer'], ['name' => 'Purchasing Officer']);
        $limitedRole->permissions()->sync(Permission::whereIn('code', ['purchases.view', 'purchases.manage'])->pluck('id'));

        $limitedUser = new User;
        $limitedUser->forceFill([
            'email' => 'purchaser@audit.local',
            'name' => 'Purchasing Officer',
            'password' => 'PurchaserPass123!Aa',
            'role_id' => $limitedRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $this->actingAs($limitedUser);
        session(['auth_version' => 1]);

        $response = $this->get('/account');
        $response->assertOk();

        // Brand link points to account since user lacks dashboard.view
        $response->assertSee('href="'.route('account').'"', false);
        $response->assertSee('href="'.route('purchases.index').'"', false);

        // Unauthorized module links must not appear
        $response->assertDontSee('href="'.route('dashboard').'"', false);
        $response->assertDontSee('href="'.route('users.index').'"', false);
        $response->assertDontSee('href="'.route('users.create').'"', false);
        $response->assertDontSee('href="'.route('roles.index').'"', false);
        $response->assertDontSee('href="'.route('roles.create').'"', false);
        $response->assertDontSee('href="'.route('categories.index').'"', false);
        $response->assertDontSee('href="'.route('products.index').'"', false);
        $response->assertDontSee('href="'.route('batches.index').'"', false);
        $response->assertDontSee('href="'.route('customers.index').'"', false);
        $response->assertDontSee('href="'.route('suppliers.index').'"', false);
        $response->assertDontSee('href="'.route('warehouses.index').'"', false);
        $response->assertDontSee('href="'.route('inventory.index').'"', false);
        $response->assertDontSee('href="'.route('inventory.transactions').'"', false);
        $response->assertDontSee('href="'.route('sales.index').'"', false);
        $response->assertDontSee('href="'.route('sales.create').'"', false);
        $response->assertDontSee('href="'.route('prices.index').'"', false);
        $response->assertDontSee('href="'.route('prices.create').'"', false);
        $response->assertDontSee('href="'.route('reports.index').'"', false);
    }

    /**
     * PERF-01: Navigation sidebar renders create or edit fallback when manage permission is granted without view.
     */
    public function test_navigation_sidebar_renders_create_or_edit_fallback_when_manage_without_view(): void
    {
        $role = Role::firstOrCreate(['code' => 'manage_only'], ['name' => 'Manage Only']);
        $role->permissions()->sync(Permission::whereIn('code', ['users.manage', 'warehouses.manage'])->pluck('id'));

        $user = new User;
        $user->forceFill([
            'email' => 'manager_only@audit.local',
            'name' => 'Manager Only',
            'password' => 'ManagerPass123!Aa',
            'role_id' => $role->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $this->actingAs($user);
        session(['auth_version' => 1]);

        $response = $this->get('/account');
        $response->assertOk();

        // Sees create/edit links for managed modules without view permission
        $response->assertSee('href="'.route('users.create').'"', false);
        $response->assertDontSee('href="'.route('users.index').'"', false);

        $response->assertSee('href="'.route('warehouses.edit').'"', false);
        $response->assertDontSee('href="'.route('warehouses.index').'"', false);
    }

    /**
     * PERF-01: Navigation sidebar renders reports link when any single report permission is granted.
     */
    public function test_navigation_sidebar_renders_reports_link_for_single_report_permission(): void
    {
        $singleReportRole = Role::firstOrCreate(['code' => 'inv_reporter'], ['name' => 'Inventory Reporter']);
        $singleReportRole->permissions()->sync(Permission::where('code', 'reports.inventory')->pluck('id'));

        $user = new User;
        $user->forceFill([
            'email' => 'reporter@audit.local',
            'name' => 'Reporter User',
            'password' => 'ReporterPass123!Aa',
            'role_id' => $singleReportRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $this->actingAs($user);
        session(['auth_version' => 1]);

        $response = $this->get('/account');
        $response->assertOk();

        // Reports link is rendered when user has any report permission
        $response->assertSee(route('reports.index'));

        // Now remove report permission and verify it disappears
        $singleReportRole->permissions()->detach();
        $responseWithoutReport = $this->get('/account');
        $responseWithoutReport->assertDontSee(route('reports.index'));
    }

    /**
     * PERF-01: Immediate permission revocation removes sidebar link and returns 403 on direct access.
     */
    public function test_navigation_sidebar_reflects_immediate_permission_revocation_and_route_returns_403(): void
    {
        $salesRole = Role::firstOrCreate(['code' => 'sales_rep'], ['name' => 'Sales Representative']);
        $salesRole->permissions()->sync(Permission::where('code', 'sales.view')->pluck('id'));

        $user = new User;
        $user->forceFill([
            'email' => 'salesrep@audit.local',
            'name' => 'Sales Rep',
            'password' => 'SalesPass123!Aa',
            'role_id' => $salesRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $this->actingAs($user);
        session(['auth_version' => 1]);

        // Initial state: link visible, route accessible
        $res1 = $this->get('/account');
        $res1->assertSee('href="'.route('sales.index').'"', false);
        $this->get('/sales')->assertOk();

        // Immediately revoke sales.view
        $salesRole->permissions()->detach(Permission::where('code', 'sales.view')->first());

        // Subsequent request: link disappears from sidebar, and route returns 403
        $res2 = $this->get('/account');
        $res2->assertDontSee('href="'.route('sales.index').'"', false);
        $this->get('/sales')->assertForbidden();
    }

    /**
     * PERF-01: Sequential users in same process do not leak navigation items across users.
     */
    public function test_navigation_sidebar_prevents_cross_user_state_leakage(): void
    {
        $limitedRole = Role::firstOrCreate(['code' => 'limited_seq'], ['name' => 'Limited Seq']);
        $limitedRole->permissions()->sync(Permission::where('code', 'purchases.view')->pluck('id'));

        $limitedUser = new User;
        $limitedUser->forceFill([
            'email' => 'limited_seq@audit.local',
            'name' => 'Limited Seq User',
            'password' => 'LimitedPass123!Aa',
            'role_id' => $limitedRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        // 1. First request as Admin: receives admin links
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);
        $resAdmin = $this->get('/account');
        $resAdmin->assertSee('href="'.route('users.index').'"', false);
        $resAdmin->assertSee('href="'.route('sales.index').'"', false);

        // 2. Second request sequentially as Limited User: must NOT receive admin links
        $this->actingAs($limitedUser);
        session(['auth_version' => 1]);
        $resLimited = $this->get('/account');
        $resLimited->assertSee('href="'.route('purchases.index').'"', false);
        $resLimited->assertDontSee('href="'.route('users.index').'"', false);
        $resLimited->assertDontSee('href="'.route('sales.index').'"', false);
        $resLimited->assertDontSee('href="'.route('dashboard').'"', false);
    }

    /**
     * PERF-01: Navigation sidebar preparation does not bypass or alter subsequent Gate checks.
     */
    public function test_navigation_sidebar_preparation_does_not_bypass_or_alter_subsequent_gate_checks(): void
    {
        $limitedRole = Role::firstOrCreate(['code' => 'gate_test_role'], ['name' => 'Gate Test Role']);
        $limitedRole->permissions()->sync(Permission::where('code', 'purchases.view')->pluck('id'));

        $user = new User;
        $user->forceFill([
            'email' => 'gate_test@audit.local',
            'name' => 'Gate Test User',
            'password' => 'GatePass123!Aa',
            'role_id' => $limitedRole->id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $this->actingAs($user);
        session(['auth_version' => 1]);

        // Render view which executes sidebar preparation
        $response = $this->get('/account');
        $response->assertOk();

        // Verify underlying Gate checks remain strictly authoritative
        $this->assertTrue(Gate::allows('purchases.view'));
        $this->assertFalse(Gate::allows('users.manage'));
        $this->assertFalse(Gate::allows('sales.view'));
        $this->assertFalse($user->hasPermission('users.manage'));

        // Revoke permission from database
        $limitedRole->permissions()->detach();

        // Re-check Gate for the exact same user instance without HTTP request or refresh
        $this->assertFalse(Gate::allows('purchases.view'));
        $this->assertFalse($user->hasPermission('purchases.view'));

        // Gate middleware must strictly block unauthorized access
        $this->get('/purchases')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/sales')->assertForbidden();
    }

    /**
     * LOGIC-02: Inventory transactions date range must reject from > to with validation error on 'to'.
     * Web requests redirect with session errors; JSON requests return 422 with unprocessable entity.
     */
    public function test_inventory_transactions_rejects_inverted_date_range_with_validation_error_on_to_field(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        // 1. Web request: from > to must redirect back with session error on 'to'
        $response = $this->from('/inventory/transactions')->get('/inventory/transactions?from=2026-11-10&to=2026-11-01');
        $response->assertRedirect('/inventory/transactions');
        $response->assertSessionHasErrors(['to']);

        // 2. JSON request: must return HTTP 422 with JSON validation errors for 'to'
        $jsonResponse = $this->getJson('/inventory/transactions?from=2026-11-10&to=2026-11-01');
        $jsonResponse->assertStatus(422);
        $jsonResponse->assertJsonValidationErrors(['to']);
    }

    /**
     * LOGIC-02: Inventory transactions date filters accept valid permutations (from=to, from<to, from alone, to alone, empty)
     * and enforce day boundaries in non-UTC timezone (Asia/Aden, UTC+3) including end of day and excluding next day.
     */
    public function test_inventory_transactions_accepts_valid_date_ranges_and_enforces_timezone_boundary(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        // 1. Valid range permutations
        $this->get('/inventory/transactions?from=2026-11-05&to=2026-11-05')->assertOk()->assertSessionHasNoErrors();
        $this->get('/inventory/transactions?from=2026-11-01&to=2026-11-05')->assertOk()->assertSessionHasNoErrors();
        $this->get('/inventory/transactions?from=2026-11-01')->assertOk()->assertSessionHasNoErrors();
        $this->get('/inventory/transactions?to=2026-11-05')->assertOk()->assertSessionHasNoErrors();
        $this->get('/inventory/transactions')->assertOk()->assertSessionHasNoErrors();
        $this->get('/inventory/transactions?from=&to=')->assertOk()->assertSessionHasNoErrors();

        // 2. Invalid date formats must be rejected
        $this->get('/inventory/transactions?from=invalid-date')->assertRedirect()->assertSessionHasErrors(['from']);
        $this->get('/inventory/transactions?to=invalid-date')->assertRedirect()->assertSessionHasErrors(['to']);

        // 3. Real data verification: non-UTC timezone (Asia/Aden = UTC+3)
        $tz = config('inventory.timezone');
        $this->assertSame('Asia/Aden', $tz);

        $warehouse = Warehouse::where('code', 'main')->firstOrFail();
        $category = Category::create(['name' => 'Cat Timezone', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Prod Timezone',
            'category_id' => $category->id,
            'pieces_per_carton' => 1,
            'purchase_unit' => 'piece',
            'selling_unit' => 'piece',
            'purchase_price' => '10.000000',
            'selling_price' => '20.000000',
            'reorder_level' => 1,
            'is_active' => true,
        ]);
        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_number' => 'BATCH-TZ',
        ]);
        $inventory = Inventory::create([
            'warehouse_id' => $warehouse->id,
            'batch_id' => $batch->id,
            'quantity' => 100,
        ]);

        // Record A: 2026-11-05 23:30:00 in Asia/Aden (2026-11-05 20:30:00 UTC) -> MUST be included in 2026-11-05
        $timeIncluded = CarbonImmutable::parse('2026-11-05 23:30:00', $tz)->utc();
        $opA = StockOperation::create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->admin->id,
            'type' => 'receipt',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'a'),
            'reference' => 'REF-INCLUDED-END-OF-DAY',
            'posted_at' => $timeIncluded,
        ]);
        InventoryTransaction::create([
            'stock_operation_id' => $opA->id,
            'inventory_id' => $inventory->id,
            'line_number' => 1,
            'allocation_number' => 1,
            'quantity' => 10,
            'input_quantity' => 10,
            'unit' => 'piece',
            'conversion_factor' => 1,
        ]);

        // Record B: 2026-11-06 00:30:00 in Asia/Aden (2026-11-05 21:30:00 UTC) -> MUST be excluded from 2026-11-05 (next day in local tz)
        $timeExcluded = CarbonImmutable::parse('2026-11-06 00:30:00', $tz)->utc();
        $opB = StockOperation::create([
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->admin->id,
            'type' => 'receipt',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'b'),
            'reference' => 'REF-EXCLUDED-NEXT-DAY',
            'posted_at' => $timeExcluded,
        ]);
        InventoryTransaction::create([
            'stock_operation_id' => $opB->id,
            'inventory_id' => $inventory->id,
            'line_number' => 1,
            'allocation_number' => 1,
            'quantity' => 10,
            'input_quantity' => 10,
            'unit' => 'piece',
            'conversion_factor' => 1,
        ]);

        $filterResponse = $this->get('/inventory/transactions?from=2026-11-05&to=2026-11-05');
        $filterResponse->assertOk();
        $filterResponse->assertSee('REF-INCLUDED-END-OF-DAY');
        $filterResponse->assertDontSee('REF-EXCLUDED-NEXT-DAY');
    }

    /**
     * Finding: Locale switcher drops current page and redirects to landing route.
     */
    public function test_locale_switcher_drops_current_url_and_redirects_to_landing_route(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        $response = $this->from('/inventory/transactions')->post('/locale', ['locale' => 'en']);
        // Redirects to dashboard instead of back to /inventory/transactions
        $response->assertRedirect(route('dashboard'));
    }

    /**
     * LOGIC-01: Sales cancellation confirmation dialog must use sales.confirm_cancel,
     * explicitly mentioning the sales order, and must never display purchase strings or unresolved keys in AR/EN.
     */
    public function test_sales_cancellation_confirmation_dialog_uses_sales_key_with_explicit_order_reference(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        $customer = Customer::create(['name' => 'Cust Audit', 'is_active' => true]);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'user_id' => $this->admin->id,
            'order_date' => '2026-11-09',
            'status' => 'draft',
            'version' => 1,
            'total_amount' => '0.000000',
            'creation_key' => '11111111-1111-1111-1111-111111111111',
            'creation_hash' => hash('sha256', 'test'),
        ]);

        // 1. Arabic Locale Check
        $arResponse = $this->withSession(['locale' => 'ar'])->get('/sales/'.$order->id);
        $arResponse->assertOk();

        // Must NOT use purchases cancel string
        $arResponse->assertDontSee(__('purchases.confirm_cancel', [], 'ar'));
        // Must use sales cancel string
        $arResponse->assertSee(__('sales.confirm_cancel', [], 'ar'));
        // Must explicitly reference sales order in Arabic
        $arResponse->assertSee('أمر البيع');
        // Must not be an unresolved key
        $arResponse->assertDontSee('sales.confirm_cancel');

        // 2. English Locale Check
        $enResponse = $this->withSession(['locale' => 'en'])->get('/sales/'.$order->id);
        $enResponse->assertOk();

        // Must NOT use purchases cancel string
        $enResponse->assertDontSee(__('purchases.confirm_cancel', [], 'en'));
        // Must use sales cancel string
        $enResponse->assertSee(__('sales.confirm_cancel', [], 'en'));
        // Must explicitly reference sales order in English
        $enResponse->assertSee('sales order');
        // Must not be an unresolved key
        $enResponse->assertDontSee('sales.confirm_cancel');

        // 3. Cancellation functionality and permissions preserved
        $cancelResponse = $this->post('/sales/'.$order->id.'/cancel', ['version' => 1]);
        $cancelResponse->assertRedirect('/sales/'.$order->id);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /**
     * Finding: Missing lang/en/validation.php leads to raw field names in English.
     */
    public function test_english_validation_file_is_missing(): void
    {
        $this->assertFileDoesNotExist(resource_path('../lang/en/validation.php'));
    }

    /**
     * Finding: Deactivated categories can be assigned to new products.
     */
    public function test_deactivated_category_can_be_assigned_to_new_product(): void
    {
        $this->actingAs($this->admin);
        session(['auth_version' => 1]);

        $inactiveCategory = Category::create(['name' => 'Inactive Cat', 'is_active' => false]);

        $response = $this->post('/products', [
            'name' => 'Product Under Inactive Category',
            'category_id' => $inactiveCategory->id,
            'pieces_per_carton' => 10,
            'purchase_unit' => 'carton',
            'selling_unit' => 'piece',
            'purchase_price' => '50.000000',
            'selling_price' => '6.000000',
            'reorder_level' => 1,
            'is_active' => true,
        ]);

        // Product is created successfully despite category being inactive
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Product Under Inactive Category', 'category_id' => $inactiveCategory->id]);
    }

    /**
     * Finding: Cosmetic updates (like changing user name) increment auth_version and revoke sessions.
     */
    public function test_cosmetic_name_change_increments_auth_version(): void
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Original Name',
            'email' => 'user@audit.local',
            'password' => 'UserPass123!Aa',
            'role_id' => $this->admin->role_id,
            'is_active' => true,
            'auth_version' => 1,
        ])->save();

        $access = app(AccessManager::class);
        $updated = $access->saveUser($this->admin, [
            'name' => 'Updated Name Typo Fix',
            'email' => 'user@audit.local',
            'role_id' => $user->role_id,
            'is_active' => true,
        ], $user);

        // auth_version is incremented from 1 to 2 solely due to name change
        $this->assertSame(2, $updated->auth_version);
    }
}
