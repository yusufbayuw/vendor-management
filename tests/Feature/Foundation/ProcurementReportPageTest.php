<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementReportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authorized_user_can_render_native_procurement_report_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.pages.reports.procurement'))
            ->assertOk()
            ->assertSee('Laporan Procurement')
            ->assertSee('Filter Periode')
            ->assertSee('Terapkan Filter')
            ->assertSee('Export CSV');
    }

    public function test_supplier_cannot_open_internal_procurement_report_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get(route('filament.admin.pages.reports.procurement'))
            ->assertForbidden();
    }
}
