<?php

namespace Tests\Feature\Foundation;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemRole;
use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Usability\WorkflowGuidanceService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowGuidanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_purchase_request_guidance_changes_with_status_and_role(): void
    {
        $requester = User::factory()->create();
        $requester->assignRole(SystemRole::Requester->value);

        $manager = User::factory()->create();
        $manager->assignRole(SystemRole::SppgManager->value);

        $service = app(WorkflowGuidanceService::class);
        $request = new PurchaseRequest(['status' => PurchaseRequestStatus::Draft]);

        $this->assertSame('Lengkapi lalu ajukan PR', $service->purchaseRequest($request, $requester));
        $this->assertSame('Menunggu pemohon mengajukan PR', $service->purchaseRequest($request, $manager));

        $request->status = PurchaseRequestStatus::Submitted;

        $this->assertSame('Periksa lalu setujui atau tolak', $service->purchaseRequest($request, $manager));
        $this->assertSame('Menunggu keputusan approval', $service->purchaseRequest($request, $requester));
    }

    public function test_internal_purchase_order_guidance_reflects_procurement_and_finance_responsibility(): void
    {
        $procurement = User::factory()->create();
        $procurement->assignRole(SystemRole::Procurement->value);

        $finance = User::factory()->create();
        $finance->assignRole(SystemRole::Finance->value);

        $service = app(WorkflowGuidanceService::class);
        $order = new PurchaseOrder(['status' => PurchaseOrderStatus::Approved]);

        $this->assertSame('Terbitkan PO ke supplier', $service->internalPurchaseOrder($order, $procurement));
        $this->assertSame('Menunggu penerbitan PO', $service->internalPurchaseOrder($order, $finance));

        $order->status = PurchaseOrderStatus::Fulfilled;

        $this->assertSame('Generate invoice dari PO', $service->internalPurchaseOrder($order, $finance));
    }

    public function test_supplier_guidance_covers_po_delivery_and_invoice_handoffs(): void
    {
        $supplier = User::factory()->create();
        $supplier->assignRole(SystemRole::SupplierOperator->value);

        $service = app(WorkflowGuidanceService::class);

        $order = new PurchaseOrder(['status' => PurchaseOrderStatus::Issued]);
        $this->assertSame('Konfirmasi Purchase Order', $service->supplierPurchaseOrder($order, $supplier));

        $order->status = PurchaseOrderStatus::Acknowledged;
        $this->assertSame('Buat jadwal pengiriman', $service->supplierPurchaseOrder($order, $supplier));

        $delivery = new DeliverySchedule(['status' => DeliveryScheduleStatus::Confirmed]);
        $this->assertSame('Isi kendaraan lalu tandai berangkat', $service->supplierDelivery($delivery, $supplier));

        $invoice = new Invoice([
            'status' => InvoiceStatus::Draft,
            'invoice_file' => null,
        ]);
        $this->assertSame('Upload file invoice lalu ajukan', $service->supplierInvoice($invoice, $supplier));

        $invoice->invoice_file = 'invoices/example.pdf';
        $this->assertSame('Ajukan invoice', $service->supplierInvoice($invoice, $supplier));
    }

    public function test_terminal_and_exception_states_have_clear_guidance(): void
    {
        $service = app(WorkflowGuidanceService::class);

        $request = new PurchaseRequest(['status' => PurchaseRequestStatus::Rejected]);
        $this->assertSame('Ditolak — tinjau alasan', $service->purchaseRequest($request, null));

        $delivery = new DeliverySchedule(['status' => DeliveryScheduleStatus::Missed]);
        $this->assertSame('Jadwal terlewat — koordinasikan ulang', $service->supplierDelivery($delivery, null));

        $invoice = new Invoice(['status' => InvoiceStatus::Paid]);
        $this->assertSame('Lunas', $service->supplierInvoice($invoice, null));
    }
}
