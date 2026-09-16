<?php

namespace Tests\Feature\Payment;

use App\Actions\Payment\AttachPaymentProofAction;
use App\Actions\Payment\CreatePaymentAction;
use App\Actions\Payment\SubmitPaymentForVerificationAction;
use App\Actions\Payment\VerifyPaymentAction;
use App\Enums\InvoiceStatus;
use App\Enums\OperationalProfile;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_full_verified_payment_closes_procurement_flow(): void
    {
        [$invoice, $actor] = $this->makeApprovedInvoice(10_000_000, OperationalProfile::Lean);

        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            10_000_000,
            PaymentMethod::BankTransfer,
            $actor,
        );
        app(AttachPaymentProofAction::class)->execute($payment, $this->storeProof('proof.pdf'), $actor);
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $actor);
        app(VerifyPaymentAction::class)->execute($payment, $actor);

        $this->assertSame(PaymentStatus::Verified, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Closed, $invoice->purchaseOrder->refresh()->status);
        $this->assertNotNull($invoice->purchaseOrder->closed_at);
    }

    public function test_partial_payment_keeps_invoice_open(): void
    {
        [$invoice, $actor] = $this->makeApprovedInvoice(10_000_000, OperationalProfile::Lean);

        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            4_000_000,
            PaymentMethod::BankTransfer,
            $actor,
        );
        app(AttachPaymentProofAction::class)->execute($payment, $this->storeProof('partial.pdf'), $actor);
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $actor);
        app(VerifyPaymentAction::class)->execute($payment, $actor);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Invoiced, $invoice->purchaseOrder->refresh()->status);
    }

    public function test_payment_cannot_be_submitted_without_proof(): void
    {
        [$invoice, $actor] = $this->makeApprovedInvoice(1_000_000, OperationalProfile::Lean);
        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            1_000_000,
            PaymentMethod::BankTransfer,
            $actor,
        );

        $this->expectException(DomainException::class);
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $actor);
    }

    public function test_strict_profile_prevents_creator_from_verifying_own_payment(): void
    {
        [$invoice, $actor] = $this->makeApprovedInvoice(1_000_000, OperationalProfile::Strict);
        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            1_000_000,
            PaymentMethod::BankTransfer,
            $actor,
        );
        app(AttachPaymentProofAction::class)->execute($payment, $this->storeProof('strict.pdf'), $actor);
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $actor);

        $this->expectException(DomainException::class);
        app(VerifyPaymentAction::class)->execute($payment, $actor);
    }

    public function test_payment_proof_rejects_extension_that_does_not_match_detected_mime(): void
    {
        [$invoice, $actor] = $this->makeApprovedInvoice(1_000_000, OperationalProfile::Lean);
        $payment = app(CreatePaymentAction::class)->execute(
            $invoice,
            1_000_000,
            PaymentMethod::BankTransfer,
            $actor,
        );
        $path = 'payments/fake.jpg';
        Storage::disk(VendorFileStorage::DISK)->put($path, "%PDF-1.4\n% test\n");

        $this->expectException(DomainException::class);
        app(AttachPaymentProofAction::class)->execute($payment, $path, $actor);
    }

    private function storeProof(string $filename): string
    {
        $path = 'payments/'.$filename;
        Storage::disk(VendorFileStorage::DISK)->put($path, "%PDF-1.4\n% test\n");

        return $path;
    }

    /** @return array{Invoice, User} */
    private function makeApprovedInvoice(float $amount, OperationalProfile $profile): array
    {
        $actor = User::factory()->create();
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-###'),
            'name' => 'Organisasi',
            'operational_profile' => $profile,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->id,
            'code' => fake()->unique()->bothify('SPPG-###'),
            'name' => 'Dapur',
        ]);
        $supplier = Supplier::query()->create([
            'code' => fake()->unique()->bothify('SUP-###'),
            'legal_name' => 'Supplier',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '08123456789',
            'status' => SupplierStatus::Active,
        ]);
        $request = PurchaseRequest::query()->create([
            'number' => fake()->unique()->bothify('PR-#####'),
            'sppg_kitchen_id' => $kitchen->id,
            'requested_by' => $actor->id,
            'status' => 'po_generated',
        ]);
        $po = PurchaseOrder::query()->create([
            'number' => fake()->unique()->bothify('PO-#####'),
            'supplier_id' => $supplier->id,
            'sppg_kitchen_id' => $kitchen->id,
            'purchase_request_id' => $request->id,
            'order_date' => today(),
            'subtotal' => $amount,
            'total_amount' => $amount,
            'status' => PurchaseOrderStatus::Invoiced,
            'created_by' => $actor->id,
        ]);
        $invoice = Invoice::query()->create([
            'number' => fake()->unique()->bothify('INV-#####'),
            'purchase_order_id' => $po->id,
            'supplier_id' => $supplier->id,
            'sppg_kitchen_id' => $kitchen->id,
            'invoice_date' => today(),
            'po_amount' => $amount,
            'total_amount' => $amount,
            'payable_amount' => $amount,
            'status' => InvoiceStatus::Approved,
            'created_by' => $actor->id,
        ]);

        return [$invoice, $actor];
    }
}
