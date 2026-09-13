<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\DeliveryQualityAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryQualityAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_delivery_quality_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/delivery-quality')
            ->assertOk()
            ->assertSee('Delivery &amp; Quality Analytics', false)
            ->assertSee('Realisasi Delivery &amp; QC', false)
            ->assertSee('Acceptance')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_delivery_quality_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/delivery-quality')
            ->assertForbidden();
    }

    public function test_delivery_quality_query_is_scoped_and_ratios_are_bounded(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $service = app(DeliveryQualityAnalyticsService::class);
        $items = $service->query($user)->get();

        $this->assertNotEmpty($items);
        $this->assertTrue($items->every(
            fn ($item): bool => (int) $item->goodsReceipt->sppg_kitchen_id === (int) $bandung->getKey(),
        ));

        foreach ($items as $item) {
            foreach ([$service->acceptanceRate($item), $service->rejectRate($item)] as $metric) {
                if ($metric === null) {
                    continue;
                }

                $this->assertGreaterThanOrEqual(0, $metric);
                $this->assertLessThanOrEqual(100, $metric);
            }
        }
    }
}
