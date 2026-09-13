<?php

namespace Tests\Feature\Analytics;

use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\ProcessPerformanceAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessPerformanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_process_performance_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/process-performance')
            ->assertOk()
            ->assertSee('Process Performance')
            ->assertSee('Procurement Cycle Performance')
            ->assertSee('PR Approval')
            ->assertSee('Tahap Terlama')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_process_performance_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/process-performance')
            ->assertForbidden();
    }

    public function test_process_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $records = app(ProcessPerformanceAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($records);
        $this->assertTrue($records->every(
            fn (PurchaseOrder $order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }

    public function test_stage_durations_use_explicit_domain_timestamps(): void
    {
        $order = PurchaseOrder::query()
            ->whereNotNull('purchase_request_id')
            ->with('purchaseRequest')
            ->firstOrFail();

        $base = Carbon::parse('2026-09-01 08:00:00');

        $order->purchaseRequest->forceFill([
            'submitted_at' => $base->copy(),
            'approved_at' => $base->copy()->addHours(2),
        ])->save();

        $order->forceFill([
            'created_at' => $base->copy()->addHours(3),
            'approved_at' => $base->copy()->addHours(5),
            'issued_at' => $base->copy()->addHours(6),
            'acknowledged_at' => $base->copy()->addHours(7),
        ])->save();

        $order->refresh()->load('purchaseRequest');
        $service = app(ProcessPerformanceAnalyticsService::class);

        $this->assertSame(2.0, $service->prApprovalHours($order));
        $this->assertSame(1.0, $service->poGenerationHours($order));
        $this->assertSame(2.0, $service->poApprovalHours($order));
        $this->assertSame(1.0, $service->issueHours($order));
        $this->assertSame(1.0, $service->acknowledgementHours($order));
    }
}
