<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\SppgAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SppgAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_native_sppg_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/sppg')
            ->assertOk()
            ->assertSee('Analitik SPPG')
            ->assertSee('Aktivitas Procurement per SPPG')
            ->assertSee('Export CSV / XLSX')
            ->assertSee('Outstanding');
    }

    public function test_supplier_cannot_open_internal_sppg_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/sppg')
            ->assertForbidden();
    }

    public function test_sppg_analytics_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();

        $orders = app(SppgAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($orders);
        $this->assertTrue($orders->every(
            fn ($order): bool => (int) $order->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }
}
