<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsErpWorkflow;
use Tests\TestCase;

class ReportHttpTest extends TestCase
{
    use BuildsErpWorkflow, RefreshDatabase;

    private array $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = $this->buildErpWorkflow();
        $this->actingAs($this->actor)->withSession(['auth_version' => $this->actor->auth_version, 'locale' => 'en']);
    }

    public function test_actual_dashboard_and_reports_are_bilingual_filtered_and_linked(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('67.500036')->assertSee('9.469136');
        $this->get('/reports')->assertOk()->assertSee('Sales report')->assertSee('Inventory alerts');
        $this->get('/reports/sales?from=2026-11-03&to=2026-11-03&customer_id='.$this->customer->id)->assertOk()->assertSee('67.500036')->assertSee($this->workflow['sale']->number());
        $this->get('/reports/purchases?supplier_id='.$this->workflow['supplier']->id)->assertOk()->assertSee('9.469136');
        $this->get('/reports/inventory?expiry_days=7')->assertOk()->assertSee('WF-2')->assertSee('Sales product');
        $this->withSession(['locale' => 'ar'])->get('/dashboard')->assertOk()->assertSee('ملخص الأعمال')->assertSee('67.500036');
        $this->get('/reports')->assertOk()->assertSee('التقارير');
        $this->get('/reports/sales')->assertOk()->assertSee('تقرير المبيعات');
        $this->get('/reports/inventory')->assertOk()->assertSee('تنبيهات المخزون');
        $this->get('/roles')->assertOk()->assertDontSee('app.permission_labels.', false);
    }

    public function test_dashboard_only_role_and_account_fallback_do_not_expose_business_numbers_or_names(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->get('/dashboard')->assertOk()->assertDontSee('67.500036')->assertDontSee('Sales customer');
        $this->get('/account')->assertOk()->assertDontSee('67.500036')->assertDontSee('Sales customer');
        foreach (['/reports', '/reports/sales', '/reports/purchases', '/reports/inventory'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_report_only_role_does_not_gain_document_links_or_other_report_domains(): void
    {
        $role = Role::where('code', 'sales')->firstOrFail();
        $role->permissions()->sync(Permission::where('code', 'reports.sales')->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id])->refresh();
        $this->actingAs($user)->withSession(['auth_version' => $user->auth_version]);
        $this->get('/reports')->assertOk()->assertSee('/reports/sales', false)->assertDontSee('/reports/purchases', false);
        $this->get('/reports/sales')->assertOk()->assertSee('67.500036')
            ->assertDontSee('href="http://127.0.0.1:8000/sales/'.$this->workflow['sale']->id.'"', false);
        $this->get('/reports/purchases')->assertForbidden();
        $this->get('/reports/inventory')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->get('/account')->assertOk()->assertDontSee('67.500036');
        $this->get('/sales/'.$this->workflow['sale']->id)->assertForbidden();
    }

    public function test_bad_dates_arrays_alerts_and_expiry_limits_fail_without_rendering_errors(): void
    {
        foreach (['/reports/sales?from[]=bad', '/reports/purchases?from=2026-11-05&to=2026-11-01',
            '/reports/sales?from=2026-02-30', '/reports/sales?customer_id[]=bad',
            '/reports/inventory?q[]=bad', '/reports/inventory?alert[]=bad', '/reports/inventory?expiry_days=0',
            '/reports/inventory?expiry_days=366', '/dashboard?expiry_days[]=bad'] as $url) {
            $this->from('/dashboard')->get($url)->assertSessionHasErrors();
            $this->get('/dashboard')->assertOk();
        }
        $this->get('/reports/inventory?expiry_days=&alert=')->assertOk();
        $result = app(BusinessReports::class)->inventory($this->actor, ['expiry_days' => '', 'alert' => '']);
        $this->assertSame(30, $result['filters']['expiry_days']);
        $this->assertSame('all', $result['filters']['alert']);
    }

    public function test_report_names_are_escaped_and_report_reads_never_write_stock(): void
    {
        $this->product->update(['name' => '<script>alert(1)</script>']);
        $this->get('/reports/inventory')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->assertDatabaseCount('inventory_transactions', 6);
        $this->artisan('erp:check-stock')->assertSuccessful();
    }
}
