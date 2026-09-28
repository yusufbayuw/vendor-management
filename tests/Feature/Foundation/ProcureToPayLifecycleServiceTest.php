<?php

namespace Tests\Feature\Foundation;

use App\Enums\BusinessFlowStage;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Models\DeliverySchedule;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Workflow\ProcureToPayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcureToPayLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_transaction_is_projected_into_six_canonical_stages(): void
    {
        [$order, $payment] = $this->completedTransaction();

        $service = app(ProcureToPayLifecycleService::class);
        $snapshot = $service->snapshot($order->refresh());

        $this->assertSame(
            ['pr', 'po', 'delivery', 'receiving', 'invoice', 'payment'],
            array_keys($snapshot),
        );

        foreach (BusinessFlowStage::cases() as $stage) {
            $this->assertTrue($snapshot[$stage->value]['is_complete'], $stage->value.' should be complete');
        }

        $this->assertSame(BusinessFlowStage::Payment, $service->currentStage($order->refresh()));
        $this->assertSame(100, $service->progress($order->refresh()));

        $context = $service->contextForEntity(Payment::class, $payment->getKey());

        $this->assertSame('payment', $context['business_stage']);
        $this->assertSame(6, $context['business_stage_order']);
        $this->assertSame($order->getKey(), $context['transaction_po_id']);
        $this->assertSame($order->number, $context['transaction_po_number']);
        $this->assertSame(100, $context['flow_progress_percent']);
    }

    public function test_downstream_data_can_infer_missing_legacy_stage_timestamps(): void
    {
        [$order] = $this->completedTransaction();

        $order->purchaseRequest->forceFill(['approved_at' => null])->save();
        $order->forceFill(['acknowledged_at' => null])->save();

        $snapshot = app(ProcureToPayLifecycleService::class)->snapshot($order->refresh());

        $this->assertTrue($snapshot['pr']['is_complete']);
        $this->assertTrue($snapshot['pr']['inferred']);
        $this->assertTrue($snapshot['po']['is_complete']);
        $this->assertTrue($snapshot['po']['inferred']);
    }

    /** @return array{PurchaseOrder, Payment} */
    private function completedTransaction(): array
    {
        $actor = User::factory()->create();
        $organization = Organization::query()->create([
            'code' => 'ORG-LIFE',
            'name' => 'Lifecycle Org',
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-LIFE',
            'name' => 'Lifecycle Kitchen',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-LIFE',
            'legal_name' => 'Lifecycle Supplier',
            'phone' => '08123456789',
            'status' => SupplierStatus::Active,
        ]);

        $request = PurchaseRequest::query()->create([
            'number' => 'PR-LIFE-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $actor->getKey(),
            'status' => PurchaseRequestStatus::PoGenerated,
            'submitted_at' => now()->subDays(6),
            'approved_at' => now()->subDays(5),
        ]);

        $order = PurchaseOrder::query()->create([
            'number' => 'PO-LIFE-001',
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'purchase_request_id' => $request->getKey(),
            'order_date' => today()->subDays(5),
            'subtotal' => 1000000,
            'total_amount' => 1000000,
            'status' => PurchaseOrderStatus::Closed,
            'approved_at' => now()->subDays(5),
            'issued_at' => now()->subDays(5),
            'acknowledged_at' => now()->subDays(4),
            'paid_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
            'created_by' => $actor->getKey(),
        ]);

        $schedule = DeliverySchedule::query()->create([
            'number' => 'DEL-LIFE-001',
            'purchase_order_id' => $order->getKey(),
            'planned_delivery_at' => now()->subDays(3),
            'status' => DeliveryScheduleStatus::Received,
            'created_by' => $actor->getKey(),
            'confirmed_by' => $actor->getKey(),
            'confirmed_at' => now()->subDays(4),
            'departed_at' => now()->subDays(3)->subHours(4),
        ]);

        GoodsReceipt::query()->create([
            'number' => 'GR-LIFE-001',
            'purchase_order_id' => $order->getKey(),
            'delivery_schedule_id' => $schedule->getKey(),
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'received_at' => now()->subDays(3),
            'received_by' => $actor->getKey(),
            'status' => GoodsReceiptStatus::Completed,
            'inspected_at' => now()->subDays(2),
            'inspected_by' => $actor->getKey(),
        ]);

        $invoice = Invoice::query()->create([
            'number' => 'INV-LIFE-001',
            'purchase_order_id' => $order->getKey(),
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'invoice_date' => today()->subDays(2),
            'po_amount' => 1000000,
            'total_amount' => 1000000,
            'payable_amount' => 1000000,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now()->subDays(2),
            'approved_at' => now()->subDay(),
            'created_by' => $actor->getKey(),
        ]);

        $payment = Payment::query()->create([
            'number' => 'PAY-LIFE-001',
            'invoice_id' => $invoice->getKey(),
            'payment_date' => today(),
            'amount' => 1000000,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Verified,
            'created_by' => $actor->getKey(),
            'verified_by' => $actor->getKey(),
            'verified_at' => now()->subHours(2),
        ]);

        return [$order, $payment];
    }
}
