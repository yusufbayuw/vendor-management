<?php

namespace Tests\Feature\Foundation;

use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Procurement\SubmitPurchaseOrderForApprovalAction;
use App\Enums\ApprovalDecisionSource;
use App\Enums\InvoiceStatus;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeanAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_lean_purchase_order_is_auto_self_approved_on_submit(): void
    {
        [$purchaseOrder, $actor] = $this->makePurchaseOrder(PurchaseOrderStatus::Draft);

        app(SubmitPurchaseOrderForApprovalAction::class)->execute($purchaseOrder, $actor);

        $this->assertSame(PurchaseOrderStatus::Approved, $purchaseOrder->refresh()->status);
        $this->assertDatabaseHas('approval_actions', [
            'actor_id' => $actor->getKey(),
            'is_self_approval' => true,
            'decision_source' => ApprovalDecisionSource::AutoSelfApproval->value,
        ]);
    }

    public function test_lean_invoice_is_auto_self_approved_but_payment_remains_separate_control(): void
    {
        [$purchaseOrder, $actor] = $this->makePurchaseOrder(PurchaseOrderStatus::Fulfilled);

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
            $purchaseOrder,
            $actor,
            'LEGACY-SUP-INV-1',
        );

        app(SubmitInvoiceAction::class)->execute($invoice, $actor);

        $this->assertSame(InvoiceStatus::Approved, $invoice->refresh()->status);
        $this->assertSame(PurchaseOrderStatus::Invoiced, $purchaseOrder->refresh()->status);
        $this->assertDatabaseHas('approval_actions', [
            'actor_id' => $actor->getKey(),
            'is_self_approval' => true,
            'decision_source' => ApprovalDecisionSource::AutoSelfApproval->value,
        ]);
    }

    /** @return array{PurchaseOrder, User} */
    private function makePurchaseOrder(PurchaseOrderStatus $status): array
    {
        $actor = User::factory()->create();
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-L-###'),
            'name' => 'Lean Organization',
            'operational_profile' => OperationalProfile::Lean,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => fake()->unique()->bothify('SPPG-L-###'),
            'name' => 'Lean Kitchen',
        ]);
        $supplier = Supplier::query()->create([
            'code' => fake()->unique()->bothify('SUP-L-###'),
            'legal_name' => 'Lean Supplier',
        ]);
        $unit = Unit::query()->create([
            'code' => fake()->unique()->bothify('U-L-###'),
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $category = ProductCategory::query()->create([
            'code' => fake()->unique()->bothify('C-L-###'),
            'name' => 'Lean Category',
        ]);
        $product = Product::query()->create([
            'category_id' => $category->getKey(),
            'default_unit_id' => $unit->getKey(),
            'code' => fake()->unique()->bothify('P-L-###'),
            'name' => 'Lean Product',
        ]);
        $purchaseRequest = PurchaseRequest::query()->create([
            'number' => fake()->unique()->bothify('PR-L-#####'),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $actor->getKey(),
            'status' => 'po_generated',
        ]);
        $requestItem = PurchaseRequestItem::query()->create([
            'purchase_request_id' => $purchaseRequest->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'requested_qty' => 10,
        ]);
        $allocation = PurchaseAllocation::query()->create([
            'purchase_request_item_id' => $requestItem->getKey(),
            'supplier_id' => $supplier->getKey(),
            'allocated_qty' => 10,
            'unit_price' => 10000,
            'subtotal' => 100000,
            'status' => 'po_generated',
            'allocated_by' => $actor->getKey(),
            'allocated_at' => now(),
        ]);
        $purchaseOrder = PurchaseOrder::query()->create([
            'number' => fake()->unique()->bothify('PO-L-#####'),
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'purchase_request_id' => $purchaseRequest->getKey(),
            'order_date' => today(),
            'subtotal' => 100000,
            'total_amount' => 100000,
            'status' => $status,
            'created_by' => $actor->getKey(),
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->getKey(),
            'purchase_request_item_id' => $requestItem->getKey(),
            'purchase_allocation_id' => $allocation->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'product_name_snapshot' => $product->name,
            'unit_name_snapshot' => $unit->symbol,
            'ordered_qty' => 10,
            'unit_price' => 10000,
            'subtotal' => 100000,
        ]);

        return [$purchaseOrder, $actor];
    }
}
