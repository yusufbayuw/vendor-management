<?php

namespace Tests\Feature\Procurement;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestViewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_scoped_user_can_open_purchase_request_detail_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $request = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-requests.view', ['record' => $request]))
            ->assertOk()
            ->assertSee('Ringkasan')
            ->assertSee('Item kebutuhan')
            ->assertSee('Riwayat proses')
            ->assertSee('PR-DEMO-WEEKLY-001')
            ->assertSee('Ayam Broiler');
    }

    public function test_procurement_user_sees_supplier_allocation_workspace_for_approved_request(): void
    {
        $user = User::query()->where('email', 'pusat@example.test')->firstOrFail();
        $request = PurchaseRequest::query()->where('number', 'PR-DEMO-DRAFT-001')->firstOrFail();
        $request->forceFill(['status' => PurchaseRequestStatus::Approved])->save();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-requests.view', ['record' => $request]))
            ->assertOk()
            ->assertSee('Alokasi Supplier per Item')
            ->assertSee('Dialokasikan')
            ->assertSee('Sisa')
            ->assertSee('Alokasikan Supplier')
            ->assertSee('Tepung Terigu');
    }

    public function test_user_cannot_open_purchase_request_outside_their_kitchen_scope(): void
    {
        $user = User::query()->where('email', 'sppg.cimahi@example.test')->firstOrFail();
        $request = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-requests.view', ['record' => $request]))
            ->assertNotFound();
    }
}
