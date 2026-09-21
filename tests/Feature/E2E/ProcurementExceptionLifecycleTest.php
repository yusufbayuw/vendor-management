<?php

namespace Tests\Feature\E2E;

use App\Actions\Billing\ApproveInvoiceAction;
use App\Actions\Supplier\ActivateSupplierWithOverrideAction;
use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Fulfillment\ApprovePurchaseOrderExceptionCloseAction;
use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Fulfillment\InspectGoodsReceiptAction;
use App\Actions\Fulfillment\RecordGoodsReceiptAction;
use App\Actions\Fulfillment\RequestPurchaseOrderExceptionCloseAction;
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
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\InvoiceStatus;
use App\Enums\OperationalProfile;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
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

class ProcurementExceptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_rejected_goods_can_be_reconciled_and_paid_after_approved_exception_closure(): void
    {
        $requester = User::factory()->create(['name' => 'Requester Exception']);
        $approver = User::factory()->create(['name' => 'Approver Exception']);
        $procurement = User::factory()->create(['name' => 'Procurement Exception']);
        $supplierActor = User::factory()->create(['name' => 'Supplier Exception']);
        $receiver = User::factory()->create(['name' => 'Receiver Exception']);
        $finance = User::factory()->create(['name' => 'Finance Exception']);
        $paymentVerifier = User::factory()->create(['name' => 'Payment Verifier Exception']);

        $organization = Organization::query()->create([
            'code' => 'ORG-E2E-EXC',
            'name' => 'Organisasi E2E Exception',
            'operational_profile' => OperationalProfile::Strict,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-E2E-EXC',
            'name' => 'Dapur SPPG E2E Exception',
        ]);
        $unit = Unit::query()->create([
            'code' => 'KG-E2E-EXC',
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $category = ProductCategory::query()->create([
            'code' => 'CAT-E2E-EXC',
            'name' => 'Protein Exception',
        ]);
        $product = Product::query()->create([
            'category_id' => $category->getKey(),
            'default_unit_id' => $unit->getKey(),
            'code' => 'AYAM-E2E-EXC',
            'name' => 'Ayam Segar Exception',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-E2E-EXC',
            'legal_name' => 'Supplier E2E Exception',
            'email' => 'supplier-e2e-exception@example.test',
            'phone' => '081234567891',
        ]);

        $supplier = app(ActivateSupplierWithOverrideAction::class)->execute(
            $supplier,
            $procurement,
            'Supplier fixture E2E dikelola internal tanpa ketergantungan akun portal.',
            true,
            true,
        );

        $purchaseRequest = PurchaseRequest::query()->create([
            'number' => 'PR-E2E-EXC-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $requester->getKey(),
        ]);
        $requestItem = PurchaseRequestItem::query()->create([
            'purchase_request_id' => $purchaseRequest->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'requested_qty' => 100,
            'estimated_unit_price' => 40_000,
            'estimated_total' => 4_000_000,
        ]);

        app(SubmitPurchaseRequestAction::class)->execute($purchaseRequest, $requester);
        app(ApprovePurchaseRequestAction::class)->execute($purchaseRequest->refresh(), $approver);
        app(AllocatePurchaseRequestItemAction::class)->execute(
            $requestItem,
            $supplier,
            100,
            40_000,
            $procurement,
        );

        $purchaseOrder = app(GeneratePurchaseOrdersAction::class)
            ->execute($purchaseRequest->refresh(), $procurement)
            ->sole();
        app(SubmitPurchaseOrderForApprovalAction::class)->execute($purchaseOrder, $procurement);
        app(ApprovePurchaseOrderAction::class)->execute($purchaseOrder->refresh(), $approver);
        app(IssuePurchaseOrderAction::class)->execute($purchaseOrder->refresh());
        app(AcknowledgePurchaseOrderAction::class)->execute($purchaseOrder->refresh(), $supplierActor);

        $purchaseOrderItem = $purchaseOrder->items()->sole();
        $schedule = app(CreateDeliveryScheduleAction::class)->execute(
            $purchaseOrder->refresh(),
            [$purchaseOrderItem->getKey() => 100],
            now()->addDay(),
            $supplierActor,
        );
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $supplierActor);

        $scheduleItem = $schedule->items()->sole();
        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$scheduleItem->getKey() => 100],
            $receiver,
        );
        $receiptItem = $receipt->items()->sole();
        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->getKey() => [
                'accepted_qty' => 95,
                'rejected_qty' => 5,
                'condition' => 'sebagian rusak',
                'rejection_reason' => 'Kemasan rusak saat diterima.',
            ],
        ], $receiver);

        $this->assertSame(PurchaseOrderStatus::PartiallyDelivered, $purchaseOrder->refresh()->status);
        $this->assertSame(95.0, (float) $purchaseOrderItem->refresh()->accepted_qty);
        $this->assertTrue($purchaseOrder->discrepancies()
            ->where('type', DiscrepancyType::RejectedGoods->value)
            ->where('status', DiscrepancyStatus::Open->value)
            ->exists());

        app(RequestPurchaseOrderExceptionCloseAction::class)->execute(
            $purchaseOrder->refresh(),
            $procurement,
            'Kekurangan 5 kg diterima sebagai exception karena kebutuhan operasional sudah selesai.',
        );
        $this->assertSame(PurchaseOrderStatus::PendingExceptionClosure, $purchaseOrder->refresh()->status);

        app(ApprovePurchaseOrderExceptionCloseAction::class)->execute(
            $purchaseOrder->refresh(),
            $approver,
            'Exception disetujui setelah verifikasi penerimaan dan QC.',
        );
        $this->assertSame(PurchaseOrderStatus::ClosedWithException, $purchaseOrder->refresh()->status);
        $this->assertSame(2, $purchaseOrder->discrepancies()->count());
        $this->assertSame(2, $purchaseOrder->discrepancies()
            ->where('status', DiscrepancyStatus::Resolved->value)
            ->where('resolution', DiscrepancyResolution::AcceptedException->value)
            ->count());

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
            $purchaseOrder->refresh(),
            $supplierActor,
            'SUP-INV-E2E-EXC-001',
        );
        app(SubmitInvoiceAction::class)->execute($invoice, $supplierActor);
        app(ApproveInvoiceAction::class)->execute($invoice->refresh(), $finance);

        $this->assertSame(4_000_000.0, (float) $invoice->refresh()->payable_amount);
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);

        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            (float) $invoice->payable_amount,
            PaymentMethod::BankTransfer,
            $finance,
        );
        app(AttachPaymentProofAction::class)->execute(
            $payment,
            $this->storeProof('exception-payment-proof.pdf'),
            $finance,
        );
        app(SubmitPaymentForVerificationAction::class)->execute($payment->refresh(), $finance);
        app(VerifyPaymentAction::class)->execute($payment->refresh(), $paymentVerifier);

        $this->assertSame(PaymentStatus::Verified, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Closed, $purchaseOrder->refresh()->status);

        $this->assertSame(5, ApprovalRequest::query()->count());
        $this->assertSame(5, ApprovalRequest::query()->where('status', ApprovalStatus::Approved->value)->count());
        $this->assertSame(0, ApprovalAction::query()->where('is_self_approval', true)->count());
    }

    private function storeProof(string $filename): string
    {
        $path = 'payments/e2e/'.$filename;
        Storage::disk(VendorFileStorage::DISK)->put($path, "%PDF-1.4\n% E2E payment proof\n");

        return $path;
    }
}
