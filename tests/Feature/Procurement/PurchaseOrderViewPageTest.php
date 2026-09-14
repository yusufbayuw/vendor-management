<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderViewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_scoped_user_can_open_purchase_order_detail_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $order = PurchaseOrder::query()
            ->whereHas('purchaseRequest', fn ($query) => $query->where('number', 'PR-DEMO-WEEKLY-001'))
            ->whereHas('items', fn ($query) => $query->where('product_name_snapshot', 'Ayam Broiler'))
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-orders.view', ['record' => $order]))
            ->assertOk()
            ->assertSee('Ringkasan')
            ->assertSee('Nilai Purchase Order')
            ->assertSee('Item Purchase Order')
            ->assertSee('Riwayat proses')
            ->assertSee($order->number)
            ->assertSee('Ayam Broiler');
    }

    public function test_user_cannot_open_purchase_order_outside_their_kitchen_scope(): void
    {
        $user = User::query()->where('email', 'sppg.cimahi@example.test')->firstOrFail();
        $order = PurchaseOrder::query()
            ->whereHas('purchaseRequest', fn ($query) => $query->where('number', 'PR-DEMO-WEEKLY-001'))
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('filament.admin.resources.purchase-orders.view', ['record' => $order]))
            ->assertNotFound();
    }
}
