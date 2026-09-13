<?php

namespace Tests\Feature\Analytics;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\FinanceAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_finance_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/finance')
            ->assertOk()
            ->assertSee('Finance Analytics')
            ->assertSee('Invoice & Settlement Analytics')
            ->assertSee('Outstanding')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_finance_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/finance')
            ->assertForbidden();
    }

    public function test_finance_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $records = app(FinanceAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($records);
        $this->assertTrue($records->every(
            fn (Invoice $invoice): bool => (int) $invoice->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }

    public function test_rejected_and_cancelled_invoices_are_not_outstanding_liabilities(): void
    {
        $invoice = Invoice::query()->firstOrFail();
        $service = app(FinanceAnalyticsService::class);

        foreach ([InvoiceStatus::Rejected, InvoiceStatus::Cancelled] as $status) {
            $invoice->forceFill([
                'status' => $status,
                'payable_amount' => 1_000_000,
            ]);

            $this->assertSame(0.0, $service->outstanding($invoice));
            $this->assertSame('Non-payable', $service->agingBucket($invoice));
            $this->assertSame(0, $service->overdueDays($invoice));
        }
    }
}
