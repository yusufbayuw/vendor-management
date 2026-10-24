<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateDeliveryScheduleAction
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /**
     * Compute remaining PO quantity consistently for both domain validation
     * and supplier portal defaults/options.
     */
    public function remainingSchedulableQuantity(PurchaseOrderItem $poItem): float
    {
        // A schedule stays reserved until its received quantity has passed QC.
        // Inspected rejected quantities may then be scheduled for replacement.
        $outstandingSchedules = DeliveryScheduleItem::query()
            ->where('purchase_order_item_id', $poItem->getKey())
            ->whereHas('deliverySchedule', fn ($query) => $query->where('status', '!=', DeliveryScheduleStatus::Cancelled->value))
            ->withSum([
                'goodsReceiptItems as inspected_received_qty' => fn ($query) => $query
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Completed->value)),
            ], 'received_qty')
            ->get()
            ->sum(static fn (DeliveryScheduleItem $scheduleItem): float => max(
                0,
                (float) $scheduleItem->planned_qty - (float) ($scheduleItem->inspected_received_qty ?? 0),
            ));

        return max(
            0,
            (float) $poItem->ordered_qty - (float) $poItem->accepted_qty - $outstandingSchedules,
        );
    }

    /** @param array<int, float|int|string> $items purchase_order_item_id => planned quantity */
    public function execute(
        PurchaseOrder $purchaseOrder,
        array $items,
        Carbon|string $plannedDeliveryAt,
        User $actor,
        ?string $supplierNotes = null,
    ): DeliverySchedule {
        if ($items === []) {
            throw new DomainException('Jadwal pengiriman harus memiliki minimal satu item.');
        }

        return DB::transaction(function () use ($purchaseOrder, $items, $plannedDeliveryAt, $actor, $supplierNotes): DeliverySchedule {
            $purchaseOrder = PurchaseOrder::query()
                ->with('kitchen')
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->getKey());

            if (! in_array($purchaseOrder->status, [
                PurchaseOrderStatus::Acknowledged,
                PurchaseOrderStatus::Scheduled,
                PurchaseOrderStatus::PartiallyDelivered,
            ], true)) {
                throw new DomainException('Jadwal pengiriman hanya dapat dibuat untuk PO yang sudah dikonfirmasi supplier.');
            }

            $schedule = DeliverySchedule::query()->create([
                'number' => $this->documentNumbers->next('DS', $purchaseOrder->kitchen),
                'purchase_order_id' => $purchaseOrder->getKey(),
                'planned_delivery_at' => Carbon::parse($plannedDeliveryAt),
                'status' => DeliveryScheduleStatus::Planned,
                'supplier_notes' => $supplierNotes,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($items as $purchaseOrderItemId => $quantityValue) {
                $quantity = (float) $quantityValue;

                if ($quantity <= 0) {
                    throw new DomainException('Jumlah jadwal pengiriman harus lebih dari nol.');
                }

                $poItem = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $purchaseOrder->getKey())
                    ->lockForUpdate()
                    ->findOrFail($purchaseOrderItemId);

                $remaining = $this->remainingSchedulableQuantity($poItem);

                if ($quantity - $remaining > 0.0001) {
                    throw new DomainException(sprintf(
                        'Jumlah jadwal untuk %s melebihi sisa PO. Sisa: %.4f.',
                        $poItem->product_name_snapshot,
                        max(0, $remaining),
                    ));
                }

                DeliveryScheduleItem::query()->create([
                    'delivery_schedule_id' => $schedule->getKey(),
                    'purchase_order_item_id' => $poItem->getKey(),
                    'planned_qty' => $quantity,
                    'unit_id' => $poItem->unit_id,
                ]);
            }

            if ($purchaseOrder->status === PurchaseOrderStatus::Acknowledged) {
                $purchaseOrder->update(['status' => PurchaseOrderStatus::Scheduled]);
            }

            return $schedule->load('items');
        }, 3);
    }
}
