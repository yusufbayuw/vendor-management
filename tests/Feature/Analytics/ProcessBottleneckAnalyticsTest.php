<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\ProcessBottleneckAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessBottleneckAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_process_bottleneck_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/process-bottlenecks')
            ->assertOk()
            ->assertSee('Process Bottleneck Analytics')
            ->assertSee('Cycle-Time Bottleneck Detection')
            ->assertSee('Diagnostic Summary')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_process_bottleneck_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/process-bottlenecks')
            ->assertForbidden();
    }

    public function test_process_bottleneck_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $orders = app(ProcessBottleneckAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($orders);
        $this->assertTrue($orders->every(
            fn ($order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }

    public function test_robust_cycle_analysis_detects_slow_and_fast_anomalies(): void
    {
        $service = app(ProcessBottleneckAnalyticsService::class);
        $baseline = collect([2.0, 2.1, 1.9, 2.2, 1.8]);

        $slow = $service->analyzeStageValues('po_approval', 'PO Approval', 12.0, $baseline);
        $fast = $service->analyzeStageValues('po_approval', 'PO Approval', 0.1, $baseline);
        $normal = $service->analyzeStageValues('po_approval', 'PO Approval', 2.0, $baseline);

        $this->assertSame('slow', $slow['status']);
        $this->assertTrue($slow['is_anomaly']);
        $this->assertGreaterThanOrEqual(ProcessBottleneckAnalyticsService::ROBUST_Z_THRESHOLD, $slow['robust_z']);

        $this->assertSame('fast', $fast['status']);
        $this->assertTrue($fast['is_anomaly']);
        $this->assertLessThanOrEqual(-ProcessBottleneckAnalyticsService::ROBUST_Z_THRESHOLD, $fast['robust_z']);

        $this->assertSame('normal', $normal['status']);
        $this->assertFalse($normal['is_anomaly']);
        $this->assertSame(5, $normal['baseline_count']);
    }

    public function test_cycle_analysis_handles_insufficient_incomplete_and_invalid_data_explicitly(): void
    {
        $service = app(ProcessBottleneckAnalyticsService::class);

        $insufficient = $service->analyzeStageValues(
            'supplier_ack',
            'Supplier Ack',
            10.0,
            collect([2.0, 2.1, 1.9, 2.2]),
        );
        $incomplete = $service->analyzeStageValues(
            'supplier_ack',
            'Supplier Ack',
            null,
            collect([2.0, 2.1, 1.9, 2.2, 2.0]),
        );
        $invalid = $service->analyzeStageValues(
            'supplier_ack',
            'Supplier Ack',
            -1.0,
            collect([2.0, 2.1, 1.9, 2.2, 2.0]),
        );

        $this->assertSame('insufficient', $insufficient['status']);
        $this->assertFalse($insufficient['is_anomaly']);
        $this->assertStringContainsString('Baseline belum cukup', $insufficient['reason']);

        $this->assertSame('incomplete', $incomplete['status']);
        $this->assertFalse($incomplete['is_anomaly']);
        $this->assertStringContainsString('belum selesai', $incomplete['reason']);

        $this->assertSame('invalid', $invalid['status']);
        $this->assertFalse($invalid['is_anomaly']);
        $this->assertStringContainsString('timestamp', $invalid['reason']);
    }

    public function test_stage_analysis_uses_domain_timestamps_and_returns_a_diagnostic_status(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $service = app(ProcessBottleneckAnalyticsService::class);
        $order = $service->query($user)->firstOrFail();
        $analyses = $service->stageAnalyses($order);

        $this->assertArrayHasKey('pr_approval', $analyses->all());
        $this->assertArrayHasKey('po_approval', $analyses->all());
        $this->assertArrayHasKey('acknowledgement', $analyses->all());
        $this->assertContains($service->overallStatus($order), [
            'bottleneck',
            'unusually_fast',
            'normal',
            'data_quality',
            'insufficient',
        ]);
    }
}
