<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\SupplierPerformanceAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPerformanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_supplier_performance_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/supplier-performance')
            ->assertOk()
            ->assertSee('Performa Supplier')
            ->assertSee('Supplier Delivery &amp; Quality Performance', false)
            ->assertSee('OTIF')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_supplier_performance_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/supplier-performance')
            ->assertForbidden();
    }

    public function test_supplier_performance_is_scoped_and_metrics_are_bounded(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $service = app(SupplierPerformanceAnalyticsService::class);
        $orders = $service->query($user)->get();

        $this->assertNotEmpty($orders);
        $this->assertTrue($orders->every(
            fn ($order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));

        foreach ($orders as $order) {
            $this->assertGreaterThan(0, $service->evaluatedDeliveryCount($order));

            foreach ([
                $service->fillRate($order),
                $service->rejectRate($order),
                $service->onTimeRate($order),
                $service->inFullRate($order),
                $service->otifRate($order),
            ] as $metric) {
                if ($metric === null) {
                    continue;
                }

                $this->assertGreaterThanOrEqual(0, $metric);
                $this->assertLessThanOrEqual(100, $metric);
            }
        }
    }
}
