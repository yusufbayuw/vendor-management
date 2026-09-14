<?php

namespace Tests\Feature\Procurement;

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

    public function test_user_cannot_open_purchase_request_outside_their_kitchen_scope(): void
    {
        $user = User::query()->where('email', 'sppg.cimahi@example.test')->firstOrFail();
        $request = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-requests.view', ['record' => $request]))
            ->assertNotFound();
    }
}
