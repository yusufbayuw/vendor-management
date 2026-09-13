<?php

namespace Tests\Feature\Analytics;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\OperationalRiskAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalRiskAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_operational_risk_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/operational-risk')
            ->assertOk()
            ->assertSee('Operational Risk Analytics')
            ->assertSee('Operational Risk &amp; Exception Monitor', false)
            ->assertSee('Alasan Risiko')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_operational_risk_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/operational-risk')
            ->assertForbidden();
    }

    public function test_operational_risk_query_is_scoped_and_has_explainable_flags(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $service = app(OperationalRiskAnalyticsService::class);
        $orders = $service->query($user)->get();

        $this->assertNotEmpty($orders);
        $this->assertTrue($orders->every(
            fn ($order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));

        $this->assertTrue($orders->contains(
            fn ($order): bool => $service->riskLevel($order) !== 'normal',
        ));

        foreach ($orders as $order) {
            $this->assertContains($service->riskLevel($order), [
                'normal',
                'medium',
                'high',
                'critical',
            ]);

            foreach ($service->riskFlags($order) as $flag) {
                $this->assertArrayHasKey('severity', $flag);
                $this->assertArrayHasKey('code', $flag);
                $this->assertArrayHasKey('label', $flag);
                $this->assertNotSame('', $flag['label']);
            }
        }
    }

    public function test_overdue_invoice_with_outstanding_balance_is_critical(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $invoice = Invoice::query()
            ->where('supplier_invoice_number', 'SUP-INV-CABAI-001')
            ->firstOrFail();

        $invoice->update([
            'due_date' => today()->subDay(),
            'status' => InvoiceStatus::PartiallyPaid,
        ]);

        $service = app(OperationalRiskAnalyticsService::class);
        $order = $service->query($user)
            ->whereKey($invoice->purchase_order_id)
            ->firstOrFail();

        $this->assertSame('critical', $service->riskLevel($order));
        $this->assertStringContainsString('Invoice lewat jatuh tempo', $service->riskReasons($order));
        $this->assertGreaterThan(0, $service->invoiceOutstanding($order));
        $this->assertGreaterThanOrEqual(1, $service->invoiceOverdueDays($order));
    }

    public function test_needs_attention_query_stays_inside_user_scope(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $service = app(OperationalRiskAnalyticsService::class);

        $orders = $service->applyNeedsAttention($service->query($user))->get();

        $this->assertNotEmpty($orders);
        $this->assertTrue($orders->every(
            fn ($order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }
}
