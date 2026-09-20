<?php

namespace Database\Seeders;

use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseAllocationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoAnalyticsSeeder extends Seeder
{
    public function run(): void
    {
        if (PurchaseOrder::query()->where('number', 'like', 'PO-HIST-%')->exists()) {
            return;
        }

        $procurement = User::query()->where('email', 'pusat@example.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@example.test')->firstOrFail();
        $financeManager = User::query()->where('email', 'finance.manager@example.test')->firstOrFail();

        $kitchens = collect([
            SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail(),
            SppgKitchen::query()->where('code', 'SPPG-CMH-001')->firstOrFail(),
            SppgKitchen::query()->where('code', 'SPPG-SMD-001')->firstOrFail(),
        ]);

        $pairs = collect([
            [
                'supplier' => Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail(),
                'product' => Product::query()->where('code', 'AYAM-BROILER')->firstOrFail(),
                'price' => 40_000.0,
                'qty' => 500.0,
            ],
            [
                'supplier' => Supplier::query()->where('code', 'SUP-PANGAN')->firstOrFail(),
                'product' => Product::query()->where('code', 'BERAS-PREMIUM')->firstOrFail(),
                'price' => 15_000.0,
                'qty' => 600.0,
            ],
            [
                'supplier' => Supplier::query()->where('code', 'SUP-TANI')->firstOrFail(),
                'product' => Product::query()->where('code', 'CABAI-MERAH')->firstOrFail(),
                'price' => 35_000.0,
                'qty' => 120.0,
            ],
        ]);

        for ($index = 0; $index < 18; $index++) {
            $monthOffset = 8 - intdiv($index, 2);
            $base = now()->startOfMonth()->subMonthsNoOverflow($monthOffset)->addDays(3 + (($index % 2) * 10))->setTime(8, 0);
            $kitchen = $kitchens[$index % $kitchens->count()];
            $pair = $pairs[$index % $pairs->count()];
            $supplier = $pair['supplier'];
            $product = $pair['product'];
            $unit = $product->defaultUnit()->firstOrFail();

            $priceFactor = match (true) {
                $index === 15 => 1.75,
                $index % 5 === 0 => 1.08,
                $index % 4 === 0 => 0.95,
                default => 1 + (($index % 3) * 0.02),
            };
            $unitPrice = round($pair['price'] * $priceFactor, 2);
            $orderedQty = $pair['qty'] + (($index % 3) * ($pair['qty'] * 0.1));
            $receivedQty = $index % 5 === 0 ? $orderedQty * 0.9 : $orderedQty;
            $rejectedQty = $index % 4 === 0 ? $receivedQty * 0.05 : 0.0;
            $acceptedQty = max(0, $receivedQty - $rejectedQty);
            $subtotal = round($orderedQty * $unitPrice, 2);

            $requester = User::query()
                ->whereHas('accessScopes', fn ($query) => $query
                    ->where('scope_type', 'sppg_kitchen')
                    ->where('scope_id', $kitchen->getKey()))
                ->first() ?? $procurement;

            $suffix = $base->format('Ym').'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            $request = PurchaseRequest::query()->create([
                'number' => 'PR-HIST-'.$suffix,
                'sppg_kitchen_id' => $kitchen->getKey(),
                'requested_by' => $requester->getKey(),
                'period_start' => $base->toDateString(),
                'period_end' => $base->copy()->addDays(6)->toDateString(),
                'needed_from' => $base->copy()->addDays(2)->toDateString(),
                'needed_until' => $base->copy()->addDays(7)->toDateString(),
                'description' => 'Dataset historis analitik '.$product->name.'.',
                'status' => PurchaseRequestStatus::PoGenerated,
                'submitted_at' => $base->copy()->addHours(1),
                'approved_at' => $base->copy()->addHours(5),
                'approved_by' => $procurement->getKey(),
            ]);
            $this->historicalTimestamps($request, $base);

            $requestItem = PurchaseRequestItem::query()->create([
                'purchase_request_id' => $request->getKey(),
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'description' => $product->name,
                'quality_specification' => 'Spesifikasi historis untuk demo analitik.',
                'requested_qty' => $orderedQty,
                'estimated_unit_price' => $unitPrice,
                'estimated_total' => $subtotal,
                'preferred_delivery_date' => $base->copy()->addDays(3)->toDateString(),
            ]);
            $this->historicalTimestamps($requestItem, $base->copy()->addMinutes(15));

            $allocation = PurchaseAllocation::query()->create([
                'purchase_request_item_id' => $requestItem->getKey(),
                'supplier_id' => $supplier->getKey(),
                'allocated_qty' => $orderedQty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'status' => PurchaseAllocationStatus::PoGenerated,
                'allocated_by' => $procurement->getKey(),
                'allocated_at' => $base->copy()->addHours(6),
            ]);
            $this->historicalTimestamps($allocation, $base->copy()->addHours(6));

            $plannedAt = $base->copy()->addDays(3)->setTime(9, 0);
            $late = $index % 3 === 0;
            $receivedAt = $late
                ? $plannedAt->copy()->addHours(5)
                : $plannedAt->copy()->subMinutes(30);

            $invoiceVariant = $index % 4;
            $poStatus = match ($invoiceVariant) {
                0, 1 => PurchaseOrderStatus::Invoiced,
                2 => PurchaseOrderStatus::Paid,
                default => PurchaseOrderStatus::Closed,
            };

            $order = PurchaseOrder::query()->create([
                'number' => 'PO-HIST-'.$suffix,
                'supplier_id' => $supplier->getKey(),
                'sppg_kitchen_id' => $kitchen->getKey(),
                'purchase_request_id' => $request->getKey(),
                'order_date' => $base->copy()->addDay()->toDateString(),
                'delivery_start' => $plannedAt->toDateString(),
                'delivery_end' => $plannedAt->copy()->addDay()->toDateString(),
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'status' => $poStatus,
                'approved_at' => $base->copy()->addDay()->addHours(2),
                'approved_by' => $procurement->getKey(),
                'issued_at' => $base->copy()->addDay()->addHours(3),
                'acknowledged_at' => $base->copy()->addDay()->addHours(5),
                'paid_at' => in_array($poStatus, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true)
                    ? $base->copy()->addDays(10)
                    : null,
                'closed_at' => $poStatus === PurchaseOrderStatus::Closed ? $base->copy()->addDays(11) : null,
                'created_by' => $procurement->getKey(),
                'notes' => 'PO historis untuk variasi analitik.',
            ]);
            $this->historicalTimestamps($order, $base->copy()->addDay());

            $orderItem = PurchaseOrderItem::query()->create([
                'purchase_order_id' => $order->getKey(),
                'purchase_request_item_id' => $requestItem->getKey(),
                'purchase_allocation_id' => $allocation->getKey(),
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'product_name_snapshot' => $product->name,
                'description_snapshot' => 'Snapshot historis '.$product->name,
                'unit_name_snapshot' => $unit->symbol ?: $unit->name,
                'ordered_qty' => $orderedQty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'delivered_qty' => $receivedQty,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
            ]);
            $this->historicalTimestamps($orderItem, $base->copy()->addDay());

            $schedule = DeliverySchedule::query()->create([
                'number' => 'DS-HIST-'.$suffix,
                'purchase_order_id' => $order->getKey(),
                'planned_delivery_at' => $plannedAt,
                'estimated_arrival_at' => $plannedAt,
                'status' => DeliveryScheduleStatus::Received,
                'driver_name' => 'Driver Historis '.($index + 1),
                'vehicle_number' => 'D '.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT).' DEMO',
                'delivery_note_number' => 'SJ-HIST-'.$suffix,
                'created_by' => $procurement->getKey(),
                'confirmed_by' => $procurement->getKey(),
                'confirmed_at' => $base->copy()->addDays(2),
                'departed_at' => $plannedAt->copy()->subHours(2),
            ]);
            $this->historicalTimestamps($schedule, $base->copy()->addDays(2));

            $scheduleItem = DeliveryScheduleItem::query()->create([
                'delivery_schedule_id' => $schedule->getKey(),
                'purchase_order_item_id' => $orderItem->getKey(),
                'planned_qty' => $orderedQty,
                'unit_id' => $unit->getKey(),
            ]);
            $this->historicalTimestamps($scheduleItem, $base->copy()->addDays(2));

            $receiver = $requester;
            $receipt = GoodsReceipt::query()->create([
                'number' => 'GR-HIST-'.$suffix,
                'purchase_order_id' => $order->getKey(),
                'delivery_schedule_id' => $schedule->getKey(),
                'supplier_id' => $supplier->getKey(),
                'sppg_kitchen_id' => $kitchen->getKey(),
                'received_at' => $receivedAt,
                'received_by' => $receiver->getKey(),
                'supplier_representative' => 'PIC '.$supplier->display_name,
                'driver_name' => $schedule->driver_name,
                'vehicle_number' => $schedule->vehicle_number,
                'delivery_note_number' => $schedule->delivery_note_number,
                'status' => GoodsReceiptStatus::Completed,
                'inspected_at' => $receivedAt->copy()->addMinutes(30),
                'inspected_by' => $receiver->getKey(),
            ]);
            $this->historicalTimestamps($receipt, $receivedAt);

            $receiptItem = GoodsReceiptItem::query()->create([
                'goods_receipt_id' => $receipt->getKey(),
                'delivery_schedule_item_id' => $scheduleItem->getKey(),
                'purchase_order_item_id' => $orderItem->getKey(),
                'planned_qty' => $orderedQty,
                'received_qty' => $receivedQty,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
                'variance_qty' => $receivedQty - $orderedQty,
                'condition' => $rejectedQty > 0 ? 'sebagian ditolak' : 'baik',
                'rejection_reason' => $rejectedQty > 0 ? 'Sebagian barang tidak memenuhi standar kualitas demo.' : null,
                'batch_number' => 'BATCH-'.$suffix,
                'expiry_date' => $base->copy()->addMonths(2)->toDateString(),
                'notes' => $late ? 'Penerimaan terlambat untuk skenario analitik.' : 'Penerimaan sesuai jadwal.',
            ]);
            $this->historicalTimestamps($receiptItem, $receivedAt);

            if ($receivedQty < $orderedQty || $rejectedQty > 0 || $late) {
                $type = $receivedQty < $orderedQty
                    ? DiscrepancyType::UnderDelivery
                    : ($rejectedQty > 0 ? DiscrepancyType::RejectedGoods : DiscrepancyType::LateDelivery);
                $open = $index % 6 === 0;

                $discrepancy = FulfillmentDiscrepancy::query()->create([
                    'purchase_order_id' => $order->getKey(),
                    'purchase_order_item_id' => $orderItem->getKey(),
                    'goods_receipt_id' => $receipt->getKey(),
                    'goods_receipt_item_id' => $receiptItem->getKey(),
                    'type' => $type,
                    'expected_qty' => $orderedQty,
                    'actual_qty' => $receivedQty,
                    'variance_qty' => $receivedQty - $orderedQty,
                    'description' => 'Exception historis untuk demo analitik.',
                    'resolution' => $open ? null : DiscrepancyResolution::AcceptedException,
                    'resolution_notes' => $open ? null : 'Exception historis diselesaikan.',
                    'status' => $open ? DiscrepancyStatus::Open : DiscrepancyStatus::Resolved,
                    'approved_by' => $open ? null : $procurement->getKey(),
                    'resolved_at' => $open ? null : $receivedAt->copy()->addDay(),
                ]);
                $this->historicalTimestamps($discrepancy, $receivedAt->copy()->addMinutes(10));
            }

            $invoiceDate = $base->copy()->addDays(7);
            $invoiceStatus = match ($invoiceVariant) {
                0 => InvoiceStatus::Approved,
                1 => InvoiceStatus::PartiallyPaid,
                2, 3 => InvoiceStatus::Paid,
            };

            $invoice = Invoice::query()->create([
                'number' => 'INV-HIST-'.$suffix,
                'supplier_invoice_number' => 'SUPINV-HIST-'.$suffix,
                'purchase_order_id' => $order->getKey(),
                'supplier_id' => $supplier->getKey(),
                'sppg_kitchen_id' => $kitchen->getKey(),
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $invoiceDate->copy()->addDays(14)->toDateString(),
                'po_amount' => $subtotal,
                'total_amount' => $subtotal,
                'payable_amount' => $subtotal,
                'status' => $invoiceStatus,
                'issued_at' => $invoiceDate,
                'approved_at' => $invoiceDate->copy()->addDay(),
                'approved_by' => $financeManager->getKey(),
                'created_by' => $finance->getKey(),
                'notes' => 'Invoice historis untuk analitik finance.',
            ]);
            $this->historicalTimestamps($invoice, $invoiceDate);

            if ($invoiceStatus !== InvoiceStatus::Approved) {
                $paymentAmount = $invoiceStatus === InvoiceStatus::PartiallyPaid ? $subtotal * 0.5 : $subtotal;

                $payment = Payment::query()->create([
                    'number' => 'PAY-HIST-'.$suffix,
                    'invoice_id' => $invoice->getKey(),
                    'payment_date' => $invoiceDate->copy()->addDays(5)->toDateString(),
                    'amount' => $paymentAmount,
                    'payment_method' => PaymentMethod::BankTransfer,
                    'source_bank_name' => 'Bank Demo',
                    'reference_number' => 'REF-HIST-'.$suffix,
                    'status' => PaymentStatus::Verified,
                    'created_by' => $finance->getKey(),
                    'verified_by' => $financeManager->getKey(),
                    'verified_at' => $invoiceDate->copy()->addDays(5)->addHour(),
                    'notes' => 'Pembayaran historis analitik.',
                ]);
                $this->historicalTimestamps($payment, $invoiceDate->copy()->addDays(5));
            }

            $approval = ApprovalRequest::query()->create([
                'organization_id' => $kitchen->organization_id,
                'approvable_type' => $order->getMorphClass(),
                'approvable_id' => $order->getKey(),
                'process' => GovernanceProcess::PurchaseOrderApproval,
                'status' => ApprovalStatus::Approved,
                'requested_by' => $procurement->getKey(),
                'amount' => $subtotal,
                'required_approvers' => 1,
                'self_approval_allowed' => $index % 7 === 0,
                'requires_override_reason' => $index % 7 === 0,
                'requested_at' => $base->copy()->addDay()->addHour(),
                'resolved_at' => $base->copy()->addDay()->addHours(2),
            ]);
            $this->historicalTimestamps($approval, $base->copy()->addDay()->addHour());

            $action = ApprovalAction::query()->create([
                'approval_request_id' => $approval->getKey(),
                'actor_id' => $procurement->getKey(),
                'action' => ApprovalActionType::Approved,
                'is_self_approval' => $index % 7 === 0,
                'comments' => 'Approval historis untuk analitik governance.',
                'override_reason' => $index % 7 === 0 ? 'Skenario demo profil operasional lean.' : null,
                'acted_at' => $base->copy()->addDay()->addHours(2),
            ]);
            $this->historicalTimestamps($action, $base->copy()->addDay()->addHours(2));

            AuditLog::query()->create([
                'actor_id' => $procurement->getKey(),
                'event' => 'updated',
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->getKey(),
                'old_values' => ['status' => PurchaseOrderStatus::Issued->value],
                'new_values' => ['status' => $poStatus->value],
                'occurred_at' => $base->copy()->addDays(4),
            ]);
        }
    }

    private function historicalTimestamps($model, $createdAt): void
    {
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }
}
