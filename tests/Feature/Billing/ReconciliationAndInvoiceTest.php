<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Fulfillment\ApprovePurchaseOrderExceptionCloseAction;
use App\Actions\Fulfillment\RequestPurchaseOrderExceptionCloseAction;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierStatus;
use App\Models\Organization;
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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @return array{PurchaseOrder, User} */
    private function makePo(PurchaseOrderStatus $status, float $ordered, float $accepted): array
    {
        $actor = User::factory()->create();
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
