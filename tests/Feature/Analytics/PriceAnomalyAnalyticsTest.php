<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\PriceAnomalyAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAnomalyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_price_anomaly_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/price-anomalies')
            ->assertOk()
            ->assertSee('Price Anomaly Detection')
            ->assertSee('Robust Price Anomaly Detection')
            ->assertSee('Baseline N')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_price_anomaly_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/price-anomalies')
            ->assertForbidden();
    }

    public function test_price_anomaly_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $items = app(PriceAnomalyAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($items);
        $this->assertTrue($items->every(
            fn ($item): bool => (int) $item->purchaseOrder->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }

    public function test_robust_statistics_detect_high_and_low_price_anomalies(): void
    {
        $service = app(PriceAnomalyAnalyticsService::class);
        $baseline = collect([100, 101, 99, 100, 102]);

        $high = $service->analyzeValues(200, $baseline);
        $low = $service->analyzeValues(50, $baseline);
        $normal = $service->analyzeValues(101, $baseline);

        $this->assertSame('high', $high['status']);
        $this->assertTrue($high['is_anomaly']);
        $this->assertGreaterThanOrEqual(PriceAnomalyAnalyticsService::ROBUST_Z_THRESHOLD, abs($high['robust_z']));

        $this->assertSame('low', $low['status']);
        $this->assertTrue($low['is_anomaly']);
        $this->assertGreaterThanOrEqual(PriceAnomalyAnalyticsService::ROBUST_Z_THRESHOLD, abs($low['robust_z']));

        $this->assertSame('normal', $normal['status']);
        $this->assertFalse($normal['is_anomaly']);
        $this->assertSame(5, $normal['baseline_count']);
        $this->assertSame(100.0, $normal['median']);
    }

    public function test_price_anomaly_requires_enough_historical_baseline(): void
    {
        $analysis = app(PriceAnomalyAnalyticsService::class)
            ->analyzeValues(200, collect([100, 101, 99, 100]));

        $this->assertSame('insufficient', $analysis['status']);
        $this->assertFalse($analysis['is_anomaly']);
        $this->assertSame(4, $analysis['baseline_count']);
        $this->assertStringContainsString('Baseline belum cukup', $analysis['reason']);
    }

    public function test_zero_dispersion_baseline_still_detects_a_real_price_change(): void
    {
        $service = app(PriceAnomalyAnalyticsService::class);
        $baseline = collect([100, 100, 100, 100, 100]);

        $same = $service->analyzeValues(100, $baseline);
        $changed = $service->analyzeValues(120, $baseline);

        $this->assertSame('normal', $same['status']);
        $this->assertFalse($same['is_anomaly']);
        $this->assertSame('high', $changed['status']);
        $this->assertTrue($changed['is_anomaly']);
        $this->assertNull($changed['robust_z']);
    }
}
