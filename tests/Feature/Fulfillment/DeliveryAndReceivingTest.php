<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\ClosePurchaseOrderWithExceptionAction;
use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Fulfillment\InspectGoodsReceiptAction;
use App\Actions\Fulfillment\RecordGoodsReceiptAction;
use App\Enums\ApprovalDecisionSource;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryAndReceivingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_po_can_be_split_into_multiple_delivery_schedules(): void
    {
        [$po, $poItem, $actor] = $this->makeAcknowledgedPo(500);

        $first = app(CreateDeliveryScheduleAction::class)->execute($po, [$poItem->id => 100], now()->addDay(), $actor);
        $second = app(CreateDeliveryScheduleAction::class)->execute($po->refresh(), [$poItem->id => 400], now()->addDays(2), $actor);

        $this->assertSame(100.0, (float) $first->items->sum('planned_qty'));
        $this->assertSame(400.0, (float) $second->items->sum('planned_qty'));
        $this->assertSame(2, $po->deliverySchedules()->count());
    }

    public function test_schedule_cannot_exceed_po_quantity(): void
    {
        [$po, $poItem, $actor] = $this->makeAcknowledgedPo(100);

        app(CreateDeliveryScheduleAction::class)->execute($po, [$poItem->id => 80], now()->addDay(), $actor);

        $this->expectException(DomainException::class);
        app(CreateDeliveryScheduleAction::class)->execute($po->refresh(), [$poItem->id => 21], now()->addDays(2), $actor);
    }

    public function test_received_goods_are_separated_from_qc_acceptance_and_rejection(): void
    {
        [$po, $poItem, $actor] = $this->makeAcknowledgedPo(100);
        $schedule = app(CreateDeliveryScheduleAction::class)->execute($po, [$poItem->id => 100], now()->addDay(), $actor);
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $actor);

        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$schedule->items->first()->id => 100],
            $actor,
        );

        $receiptItem = $receipt->items->first();
        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->id => [
                'accepted_qty' => 95,
                'rejected_qty' => 5,
                'condition' => 'sebagian rusak',
                'rejection_reason' => 'Kemasan rusak.',
            ],
        ], $actor);

        $this->assertSame(100.0, (float) $poItem->refresh()->delivered_qty);
        $this->assertSame(95.0, (float) $poItem->accepted_qty);
        $this->assertSame(5.0, (float) $poItem->rejected_qty);
        $this->assertSame(PurchaseOrderStatus::PartiallyDelivered, $po->refresh()->status);
        $this->assertTrue($po->discrepancies()->where('type', DiscrepancyType::RejectedGoods->value)->exists());
    }

    public function test_lean_exception_close_is_one_intentional_interaction_with_audit_record(): void
    {
        [$po, $poItem, $actor] = $this->makeAcknowledgedPo(100, OperationalProfile::Lean);
        $actor->givePermissionTo(
            Permission::findOrCreate(SystemPermission::PurchaseOrderExceptionClose->value, 'web'),
        );

        $poItem->forceFill([
            'delivered_qty' => 80,
            'accepted_qty' => 80,
        ])->save();
        $po->forceFill(['status' => PurchaseOrderStatus::PartiallyDelivered])->save();

        app(ClosePurchaseOrderWithExceptionAction::class)->execute(
            $po,
            $actor,
            'Sisa 20 unit dibatalkan berdasarkan kesepakatan operasional.',
        );

        $this->assertSame(PurchaseOrderStatus::ClosedWithException, $po->refresh()->status);
        $this->assertSame(
            0,
            $po->discrepancies()->where('status', DiscrepancyStatus::Open->value)->count(),
        );
        $this->assertDatabaseHas('approval_actions', [
            'actor_id' => $actor->getKey(),
            'is_self_approval' => true,
            'decision_source' => ApprovalDecisionSource::ExplicitSelfApproval->value,
        ]);
    }

    public function test_full_accepted_quantity_marks_po_fulfilled(): void
    {
        [$po, $poItem, $actor] = $this->makeAcknowledgedPo(100);
        $schedule = app(CreateDeliveryScheduleAction::class)->execute($po, [$poItem->id => 100], now()->addDay(), $actor);
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $actor);
        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$schedule->items->first()->id => 100],
            $actor,
        );
        $receiptItem = $receipt->items->first();

        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->id => [
                'accepted_qty' => 100,
                'rejected_qty' => 0,
                'condition' => 'baik',
            ],
        ], $actor);

        $this->assertSame(PurchaseOrderStatus::Fulfilled, $po->refresh()->status);
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem, User} */
    private function makeAcknowledgedPo(
        float $quantity,
        OperationalProfile $profile = OperationalProfile::Standard,
    ): array {
        $actor = User::factory()->create();
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-###'),
            'name' => 'Organisasi',
            'operational_profile' => $profile,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->id,
            'code' => fake()->unique()->bothify('SPPG-###'),
            'name' => 'Dapur SPPG',
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
            'requested_qty' => $quantity,
        ]);
        $allocation = PurchaseAllocation::query()->create([
            'purchase_request_item_id' => $requestItem->id,
            'supplier_id' => $supplier->id,
            'allocated_qty' => $quantity,
            'unit_price' => 40_000,
            'subtotal' => $quantity * 40_000,
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
            'subtotal' => $quantity * 40_000,
            'total_amount' => $quantity * 40_000,
            'status' => PurchaseOrderStatus::Acknowledged,
            'created_by' => $actor->id,
        ]);
        $poItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $po->id,
            'purchase_request_item_id' => $requestItem->id,
            'purchase_allocation_id' => $allocation->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'product_name_snapshot' => 'Ayam',
            'unit_name_snapshot' => 'kg',
            'ordered_qty' => $quantity,
            'unit_price' => 40_000,
            'subtotal' => $quantity * 40_000,
        ]);

        return [$po, $poItem, $actor];
    }
}
