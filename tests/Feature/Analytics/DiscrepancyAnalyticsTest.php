<?php

namespace Tests\Feature\Analytics;

use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\DiscrepancyAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscrepancyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_discrepancy_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/discrepancies')
            ->assertOk()
            ->assertSee('Discrepancy Analytics')
            ->assertSee('Fulfillment Discrepancy')
            ->assertSee('Variance %')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_discrepancy_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/discrepancies')
            ->assertForbidden();
    }

    public function test_discrepancy_query_never_leaks_another_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $service = app(DiscrepancyAnalyticsService::class);
        $records = $service->query($user)->get();

        $this->assertNotEmpty($records);
        $this->assertTrue($records->every(
            fn ($record): bool => (int) $record->purchaseOrder->sppg_kitchen_id === (int) $bandung->getKey(),
        ));
    }
}
