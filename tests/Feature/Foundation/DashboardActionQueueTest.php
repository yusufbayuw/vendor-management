<?php

namespace Tests\Feature\Foundation;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardActionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_requester_dashboard_surfaces_actionable_draft_purchase_request(): void
    {
        $requester = User::query()->where('email', 'role.requester@example.test')->firstOrFail();
        $kitchen = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();

        PurchaseRequest::query()->create([
            'number' => 'PR-WORK-QUEUE-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $requester->getKey(),
            'needed_from' => today()->addDay(),
            'needed_until' => today()->addDays(2),
            'description' => 'PR untuk pengujian task queue requester.',
        ]);

        $this->actingAs($requester)
            ->get('/admin')
            ->assertOk()
            ->assertSee('PR Draft Saya')
            ->assertSee('Lengkapi dan ajukan PR');
    }

    public function test_supplier_dashboard_surfaces_issued_purchase_order_action(): void
    {
        $supplierUser = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();
        $kitchen = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $requester = User::query()->where('email', 'role.requester@example.test')->firstOrFail();
        $procurement = User::query()->where('email', 'role.procurement@example.test')->firstOrFail();

        $request = PurchaseRequest::query()->create([
            'number' => 'PR-WORK-QUEUE-SUP-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $requester->getKey(),
            'needed_from' => today()->addDay(),
            'needed_until' => today()->addDays(2),
            'description' => 'PR untuk pengujian task queue supplier.',
        ]);

        PurchaseOrder::query()->create([
            'number' => 'PO-WORK-QUEUE-SUP-001',
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'purchase_request_id' => $request->getKey(),
            'order_date' => today(),
            'status' => PurchaseOrderStatus::Issued,
            'issued_at' => now(),
            'created_by' => $procurement->getKey(),
        ]);

        $this->actingAs($supplierUser)
            ->get('/supplier')
            ->assertOk()
            ->assertSee('PO Perlu Konfirmasi')
            ->assertSee('Konfirmasi Purchase Order baru');
    }

    public function test_auditor_dashboard_does_not_show_operational_action_queue(): void
    {
        $auditor = User::query()->where('email', 'role.auditor@example.test')->firstOrFail();

        $this->actingAs($auditor)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('PR Draft Saya')
            ->assertDontSee('Verifikasi Pembayaran');
    }
}
