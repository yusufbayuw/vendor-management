<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\CommodityAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommodityAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_native_commodity_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/commodities')
            ->assertOk()
            ->assertSee('Analitik Komoditas')
            ->assertSee('Realisasi Pengadaan Komoditas')
            ->assertSee('Diterima QC')
            ->assertSee('Export CSV / XLSX');

        $this->assertTrue(Schema::hasTable('exports'));
    }

    public function test_supplier_cannot_open_internal_commodity_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/commodities')
            ->assertForbidden();
    }

    public function test_commodity_analytics_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();

        $rows = app(CommodityAnalyticsService::class)->query($user, [
            'from' => today()->subYear()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ])->get();

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(
            fn ($row): bool => (int) $row->purchaseOrder->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }
}
