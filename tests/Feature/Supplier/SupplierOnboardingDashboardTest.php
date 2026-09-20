<?php

namespace Tests\Feature\Supplier;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierOnboardingDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_supplier_sees_onboarding_guidance_on_dashboard(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'supplier.pending.operator@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/supplier')
            ->assertOk()
            ->assertSee('Status Pendaftaran')
            ->assertSee('Progress Onboarding')
            ->assertSee('Profil Supplier')
            ->assertSee('Dokumen Legal')
            ->assertSee('Rekening Bank')
            ->assertSee('Katalog Produk');
    }

    public function test_active_supplier_with_incomplete_profile_still_sees_onboarding_widget(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/supplier')
            ->assertOk()
            ->assertSee('Progress Onboarding');
    }

    public function test_onboarding_widget_disappears_after_profile_is_complete(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();
        $supplier = $user->suppliers()->wherePivot('is_active', true)->firstOrFail();

        $supplier->forceFill([
            'regency_code' => '3273',
            'district_code' => '3273010',
            'village_code' => '3273010001',
        ])->save();

        $this->actingAs($user)
            ->get('/supplier')
            ->assertOk()
            ->assertDontSee('Progress Onboarding');
    }
}
