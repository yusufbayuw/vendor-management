<?php

namespace Tests\Feature\E2E;

use App\Actions\Billing\ApproveInvoiceAction;
use App\Actions\Supplier\ActivateSupplierWithOverrideAction;
use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Fulfillment\InspectGoodsReceiptAction;
use App\Actions\Fulfillment\MarkDeliveryInTransitAction;
use App\Actions\Fulfillment\RecordGoodsReceiptAction;
use App\Actions\Payment\AttachPaymentProofAction;
use App\Actions\Payment\CreatePaymentAction;
use App\Actions\Payment\SubmitPaymentForVerificationAction;
use App\Actions\Payment\VerifyPaymentAction;
use App\Actions\Procurement\AcknowledgePurchaseOrderAction;
use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Actions\Procurement\ApprovePurchaseOrderAction;
use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Actions\Procurement\IssuePurchaseOrderAction;
use App\Actions\Procurement\SubmitPurchaseOrderForApprovalAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OperationalProfile;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcurementLifecycleHappyPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_strict_profile_completes_procurement_lifecycle_from_pr_to_verified_payment(): void
    {
        $requester = User::factory()->create(['name' => 'Requester SPPG']);
        $approver = User::factory()->create(['name' => 'Approver']);
        $procurement = User::factory()->create(['name' => 'Procurement']);
        $supplierActor = User::factory()->create(['name' => 'Supplier Operator']);
        $receiver = User::factory()->create(['name' => 'Receiver QC']);
        $finance = User::factory()->create(['name' => 'Finance']);
        $paymentVerifier = User::factory()->create(['name' => 'Payment Verifier']);

        $organization = Organization::query()->create([
            'code' => 'ORG-E2E-001',
            'name' => 'Organisasi E2E',
            'operational_profile' => OperationalProfile::Strict,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-E2E-001',
            'name' => 'Dapur SPPG E2E',
        ]);
        $unit = Unit::query()->create([
            'code' => 'KG-E2E',
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $category = ProductCategory::query()->create([
            'code' => 'CAT-E2E',
            'name' => 'Protein',
        ]);
        $product = Product::query()->create([
            'category_id' => $category->getKey(),
            'default_unit_id' => $unit->getKey(),
            'code' => 'AYAM-E2E',
            'name' => 'Ayam Segar E2E',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-E2E-001',
            'legal_name' => 'Supplier E2E',
            'email' => 'supplier-e2e@example.test',
            'phone' => '081234567890',
        ]);

        $supplier = app(ActivateSupplierWithOverrideAction::class)->execute(
            $supplier,
            $procurement,
            'Supplier fixture E2E dikelola internal tanpa ketergantungan akun portal.',
            true,
            true,
        );

        $purchaseRequest = PurchaseRequest::query()->create([
            'number' => 'PR-E2E-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $requester->getKey(),
            'needed_from' => today()->addDay(),
            'needed_until' => today()->addDays(2),
            'description' => 'Happy path procurement E2E.',
        ]);
        $requestItem = PurchaseRequestItem::query()->create([
            'purchase_request_id' => $purchaseRequest->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => $product->name,
            'requested_qty' => 100,
            'estimated_unit_price' => 40_000,
            'estimated_total' => 4_000_000,
        ]);

        app(SubmitPurchaseRequestAction::class)->execute($purchaseRequest, $requester);
        app(ApprovePurchaseRequestAction::class)->execute($purchaseRequest->refresh(), $approver, 'PR disetujui.');
        $this->assertSame(PurchaseRequestStatus::Approved, $purchaseRequest->refresh()->status);

        app(AllocatePurchaseRequestItemAction::class)->execute(
            $requestItem,
            $supplier,
            100,
            40_000,
            $procurement,
        );
        $this->assertSame(PurchaseRequestStatus::FullyAllocated, $purchaseRequest->refresh()->status);

        $purchaseOrder = app(GeneratePurchaseOrdersAction::class)
            ->execute($purchaseRequest->refresh(), $procurement)
            ->sole();
        $this->assertSame(PurchaseOrderStatus::Draft, $purchaseOrder->status);
        $this->assertSame(PurchaseRequestStatus::PoGenerated, $purchaseRequest->refresh()->status);

        app(SubmitPurchaseOrderForApprovalAction::class)->execute($purchaseOrder, $procurement);
        app(ApprovePurchaseOrderAction::class)->execute($purchaseOrder->refresh(), $approver, 'PO disetujui.');
        app(IssuePurchaseOrderAction::class)->execute($purchaseOrder->refresh());
        app(AcknowledgePurchaseOrderAction::class)->execute(
            $purchaseOrder->refresh(),
            $supplierActor,
            'PO diterima supplier.',
        );
        $this->assertSame(PurchaseOrderStatus::Acknowledged, $purchaseOrder->refresh()->status);

        $purchaseOrderItem = $purchaseOrder->items()->sole();
        $schedule = app(CreateDeliveryScheduleAction::class)->execute(
            $purchaseOrder->refresh(),
            [$purchaseOrderItem->getKey() => 100],
            now()->addDay(),
            $supplierActor,
        );
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $supplierActor);
        app(MarkDeliveryInTransitAction::class)->execute($schedule->refresh(), [
            'driver_name' => 'Driver E2E',
            'driver_phone' => '081298765432',
            'vehicle_number' => 'D 1234 E2E',
            'delivery_note_number' => 'SJ-E2E-001',
        ]);
        $this->assertSame(DeliveryScheduleStatus::InTransit, $schedule->refresh()->status);

        $scheduleItem = $schedule->items()->sole();
        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$scheduleItem->getKey() => 100],
            $receiver,
            'Perwakilan Supplier E2E',
        );
        $this->assertSame(GoodsReceiptStatus::PendingInspection, $receipt->status);
        $this->assertSame(DeliveryScheduleStatus::Received, $schedule->refresh()->status);

        $receiptItem = $receipt->items()->sole();
        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->getKey() => [
                'accepted_qty' => 100,
                'rejected_qty' => 0,
                'condition' => 'baik',
                'notes' => 'Seluruh barang lolos QC.',
            ],
        ], $receiver);
        $this->assertSame(GoodsReceiptStatus::Completed, $receipt->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Fulfilled, $purchaseOrder->refresh()->status);
        $this->assertSame(100.0, (float) $purchaseOrderItem->refresh()->accepted_qty);

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
            $purchaseOrder->refresh(),
            $supplierActor,
            'SUP-INV-E2E-001',
            today(),
            7,
        );
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        app(SubmitInvoiceAction::class)->execute($invoice, $supplierActor);
        app(ApproveInvoiceAction::class)->execute($invoice->refresh(), $finance, 'Invoice sesuai PO dan GR.');
        $this->assertSame(InvoiceStatus::Approved, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Invoiced, $purchaseOrder->refresh()->status);

        $payment = app(CreatePaymentAction::class)->execute(
            $invoice->refresh(),
            (float) $invoice->payable_amount,
            PaymentMethod::BankTransfer,
            $finance,
        );
        app(AttachPaymentProofAction::class)->execute(
            $payment,
            $this->storeProof('payment-proof.pdf'),
            $finance,
        );
        app(SubmitPaymentForVerificationAction::class)->execute($payment->refresh(), $finance);
        app(VerifyPaymentAction::class)->execute($payment->refresh(), $paymentVerifier);

        $this->assertSame(PaymentStatus::Verified, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Closed, $purchaseOrder->refresh()->status);
        $this->assertNotNull($purchaseOrder->closed_at);
        $this->assertSame(4_000_000.0, (float) $invoice->payable_amount);
        $this->assertSame(4_000_000.0, (float) $payment->amount);

        $this->assertSame(4, ApprovalRequest::query()->count());
        $this->assertSame(4, ApprovalRequest::query()->where('status', ApprovalStatus::Approved->value)->count());
        $this->assertSame(0, ApprovalAction::query()->where('is_self_approval', true)->count());
    }

    private function storeProof(string $filename): string
    {
        $path = 'payments/e2e/'.$filename;
        Storage::disk(VendorFileStorage::DISK)->put($path, "%PDF-1.4\n% E2E payment proof\n");

        return $path;
    }
}
