<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\AddInvoiceAdjustmentAction;
use App\Actions\Billing\CreateAndSubmitInvoiceAction;
use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Billing\ReturnInvoiceForRevisionAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Fulfillment\ApprovePurchaseOrderExceptionCloseAction;
use App\Actions\Fulfillment\RequestPurchaseOrderExceptionCloseAction;
use App\Enums\AccessScopeType;
use App\Enums\ApprovalStatus;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\InvoiceAdjustmentDirection;
use App\Enums\InvoiceAdjustmentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Models\GovernancePolicy;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAccessScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReconciliationAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_under_delivery_can_be_closed_with_exception_in_lean_operation(): void
    {
        [$po, $actor] = $this->makePo(PurchaseOrderStatus::PartiallyDelivered, 500, 499);

        app(RequestPurchaseOrderExceptionCloseAction::class)->execute($po, $actor, 'Selisih timbang 1 kg disetujui.');
        $this->assertSame(PurchaseOrderStatus::PendingExceptionClosure, $po->refresh()->status);

        app(ApprovePurchaseOrderExceptionCloseAction::class)->execute(
            $po,
            $actor,
            'Kekurangan 1 kg diterima sebagai exception operasional.',
        );

        $this->assertSame(PurchaseOrderStatus::ClosedWithException, $po->refresh()->status);
        $this->assertTrue($po->discrepancies()
            ->where('type', DiscrepancyType::UnderDelivery->value)
            ->where('status', DiscrepancyStatus::Resolved->value)
            ->exists());
    }

    public function test_lean_supplier_can_create_and_submit_invoice_in_one_action(): void
    {
        [$po, $actor] = $this->makePo(PurchaseOrderStatus::Fulfilled, 500, 500);

        $invoice = app(CreateAndSubmitInvoiceAction::class)->execute(
            $po,
            $actor,
            'SUP-INV-LEAN-001',
            today(),
            7,
        );

        $this->assertSame(InvoiceStatus::Submitted, $invoice->refresh()->status);
        $this->assertNotNull($invoice->issued_at);
    }

    public function test_invoice_total_remains_po_total_after_exception_closure(): void
    {
        [$po, $actor] = $this->makePo(PurchaseOrderStatus::ClosedWithException, 500, 499);
        $po->forceFill(['total_amount' => 20_000_000])->save();

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute($po, $actor, 'SUP-INV-001');

        $this->assertSame(20_000_000.0, (float) $invoice->po_amount);
        $this->assertSame(20_000_000.0, (float) $invoice->total_amount);
        $this->assertSame(20_000_000.0, (float) $invoice->payable_amount);
    }

    public function test_invoice_cannot_be_created_before_po_is_reconciled(): void
    {
        [$po, $actor] = $this->makePo(PurchaseOrderStatus::PartiallyDelivered, 500, 499);

        $this->expectException(DomainException::class);
        app(CreateInvoiceFromPurchaseOrderAction::class)->execute($po, $actor);
    }

    public function test_invoice_approval_amount_cannot_change_after_submission(): void
    {
        [$po, $actor] = $this->makePo(PurchaseOrderStatus::Fulfilled, 100, 100);
        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute($po, $actor);

        app(AddInvoiceAdjustmentAction::class)->execute(
            $invoice,
            InvoiceAdjustmentType::Correction,
            InvoiceAdjustmentDirection::Deduction,
            1_000,
            'Penyesuaian nilai yang disepakati sebelum diajukan.',
            $actor,
        );

        $this->assertSame(3_999_000.0, (float) $invoice->refresh()->payable_amount);

        app(SubmitInvoiceAction::class)->execute($invoice, $actor);

        $this->assertSame(InvoiceStatus::Submitted, $invoice->refresh()->status);
        $approval = $invoice->approvalRequests()->sole();
        $this->assertSame(3_999_000.0, (float) $approval->amount);

        try {
            app(AddInvoiceAdjustmentAction::class)->execute(
                $invoice,
                InvoiceAdjustmentType::Correction,
                InvoiceAdjustmentDirection::Addition,
                5_000,
                'Perubahan setelah approval diminta tidak diizinkan.',
                $actor,
            );
            $this->fail('The submitted invoice approval amount must be immutable.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sebelum diajukan', $exception->getMessage());
        }

        $this->assertSame(3_999_000.0, (float) $invoice->refresh()->payable_amount);
        $this->assertSame(3_999_000.0, (float) $approval->refresh()->amount);
        $this->assertSame(1, $invoice->adjustments()->count());
    }

    public function test_return_for_revision_rejects_old_approval_and_resubmits_new_threshold_snapshot(): void
    {
        [$po, $author] = $this->makePo(PurchaseOrderStatus::Fulfilled, 100, 100);
        $organization = $po->kitchen->organization;

        GovernancePolicy::query()->create([
            'organization_id' => $organization->getKey(),
            'process' => GovernanceProcess::InvoiceApproval,
            'amount_threshold' => null,
            'self_approval_allowed' => false,
            'minimum_approvers' => 1,
            'requires_override_reason' => false,
            'is_active' => true,
        ]);
        GovernancePolicy::query()->create([
            'organization_id' => $organization->getKey(),
            'process' => GovernanceProcess::InvoiceApproval,
            'amount_threshold' => 4_000_000,
            'self_approval_allowed' => false,
            'minimum_approvers' => 2,
            'requires_override_reason' => false,
            'is_active' => true,
        ]);

        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(Permission::findOrCreate(SystemPermission::InvoiceApprove->value, 'web'));
        UserAccessScope::query()->create([
            'user_id' => $reviewer->getKey(),
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute($po, $author);
        app(SubmitInvoiceAction::class)->execute($invoice, $author);
        $original = $invoice->approvalRequests()->sole();

        $this->assertSame(4_000_000.0, (float) $original->amount);
        $this->assertSame(2, $original->required_approvers);

        try {
            app(ReturnInvoiceForRevisionAction::class)->execute(
                $invoice, User::factory()->create(), 'Koreksi nilai.',
            );
            $this->fail('Unscoped actor must not return the invoice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('izin', $exception->getMessage());
        }

        app(ReturnInvoiceForRevisionAction::class)->execute(
            $invoice, $reviewer, 'Kurangi item yang tidak dapat ditagihkan.',
        );

        $this->assertSame(InvoiceStatus::Draft, $invoice->refresh()->status);
        $this->assertNull($invoice->issued_at);
        $this->assertSame(ApprovalStatus::Rejected, $original->refresh()->status);
        $this->assertCount(1, $original->actions);
        $this->assertStringContainsString('Kurangi item', $invoice->notes);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $invoice->getMorphClass(),
            'auditable_id' => $invoice->getKey(),
            'event' => 'invoice_returned_for_revision',
        ]);

        app(AddInvoiceAdjustmentAction::class)->execute(
            $invoice,
            InvoiceAdjustmentType::Correction,
            InvoiceAdjustmentDirection::Deduction,
            1_000_000,
            'Koreksi barang ditolak.',
            $author,
        );

        app(SubmitInvoiceAction::class)->execute($invoice->refresh(), $author);
        $new = $invoice->approvalRequests()->latest('id')->firstOrFail();

        $this->assertSame(InvoiceStatus::Submitted, $invoice->refresh()->status);
        $this->assertNotNull($invoice->issued_at);
        $this->assertSame(2, $invoice->approvalRequests()->count());
        $this->assertNotSame($original->getKey(), $new->getKey());
        $this->assertSame(ApprovalStatus::Rejected, $original->refresh()->status);
        $this->assertSame(ApprovalStatus::Pending, $new->status);
        $this->assertSame(3_000_000.0, (float) $new->amount);
        $this->assertSame(1, $new->required_approvers);
    }

    public function test_return_for_revision_refuses_approved_or_payment_committed_invoice(): void
    {
        [$po, $author] = $this->makePo(PurchaseOrderStatus::Fulfilled, 100, 100);
        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute($po, $author);
        app(SubmitInvoiceAction::class)->execute($invoice, $author);

        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(Permission::findOrCreate(SystemPermission::InvoiceApprove->value, 'web'));
        UserAccessScope::query()->create([
            'user_id' => $reviewer->getKey(),
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);

        $invoice->forceFill(['status' => InvoiceStatus::Approved])->save();

        try {
            app(ReturnInvoiceForRevisionAction::class)->execute($invoice, $reviewer, 'Terlambat.');
            $this->fail('Approved invoice must not be revised.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('menunggu persetujuan', $exception->getMessage());
        }

        // Defensive guard for legacy/imported data even when status is stale.
        $invoice->forceFill(['status' => InvoiceStatus::Submitted])->save();
        Payment::query()->create([
            'number' => 'PAY-REVISION-GUARD',
            'invoice_id' => $invoice->getKey(),
            'payment_date' => today(),
            'amount' => 500_000,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Draft,
            'created_by' => $author->getKey(),
        ]);

        try {
            app(ReturnInvoiceForRevisionAction::class)->execute($invoice, $reviewer, 'Ada pembayaran.');
            $this->fail('Invoice with any payment record must not be revised.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('riwayat pembayaran', $exception->getMessage());
        }

        $this->assertSame(InvoiceStatus::Submitted, $invoice->refresh()->status);
        $this->assertSame(ApprovalStatus::Pending, $invoice->approvalRequests()->sole()->status);
    }

    /** @return array{PurchaseOrder, User} */
    private function makePo(PurchaseOrderStatus $status, float $ordered, float $accepted): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate(SystemPermission::PurchaseOrderExceptionClose->value, 'web'));
        UserAccessScope::query()->create([
            'user_id' => $actor->getKey(),
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-###'),
            'name' => 'Organisasi',
            'operational_profile' => OperationalProfile::Lean,
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
        $unit = Unit::query()->create(['code' => fake()->unique()->bothify('KG-###'), 'name' => 'Kilogram', 'symbol' => 'kg']);
        $category = ProductCategory::query()->create(['code' => fake()->unique()->bothify('CAT-###'), 'name' => 'Protein']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'default_unit_id' => $unit->id,
            'code' => fake()->unique()->bothify('P-###'),
            'name' => 'Ayam',
        ]);
        $request = PurchaseRequest::query()->create([
            'number' => fake()->unique()->bothify('PR-#####'),
            'sppg_kitchen_id' => $kitchen->id,
            'requested_by' => $actor->id,
            'status' => 'po_generated',
        ]);
        $requestItem = PurchaseRequestItem::query()->create([
            'purchase_request_id' => $request->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'requested_qty' => $ordered,
        ]);
        $allocation = PurchaseAllocation::query()->create([
            'purchase_request_item_id' => $requestItem->id,
            'supplier_id' => $supplier->id,
            'allocated_qty' => $ordered,
            'unit_price' => 40_000,
            'subtotal' => $ordered * 40_000,
            'status' => 'po_generated',
            'allocated_by' => $actor->id,
            'allocated_at' => now(),
        ]);
        $po = PurchaseOrder::query()->create([
            'number' => fake()->unique()->bothify('PO-#####'),
            'supplier_id' => $supplier->id,
            'sppg_kitchen_id' => $kitchen->id,
            'purchase_request_id' => $request->id,
            'order_date' => today(),
            'subtotal' => $ordered * 40_000,
            'total_amount' => $ordered * 40_000,
            'status' => $status,
            'created_by' => $actor->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'purchase_request_item_id' => $requestItem->id,
            'purchase_allocation_id' => $allocation->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'product_name_snapshot' => 'Ayam',
            'unit_name_snapshot' => 'kg',
            'ordered_qty' => $ordered,
            'unit_price' => 40_000,
            'subtotal' => $ordered * 40_000,
            'delivered_qty' => $accepted,
            'accepted_qty' => $accepted,
            'rejected_qty' => 0,
        ]);

        return [$po, $actor];
    }
}
