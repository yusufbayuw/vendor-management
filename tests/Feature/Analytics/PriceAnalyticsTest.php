<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_native_price_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/prices')
            ->assertOk()
            ->assertSee('Analitik Harga')
            ->assertSee('Histori &amp; Perbandingan Harga Komoditas', false)
            ->assertSee('Harga Satuan')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_price_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/prices')
            ->assertForbidden();
    }
}
