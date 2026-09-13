<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\GoodsReceiptStatus;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\User;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordGoodsReceiptAction
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /** @param array<int, float|int|string> $items delivery_schedule_item_id => received quantity */
    public function execute(
        DeliverySchedule $schedule,
        array $items,
        User $actor,
        ?string $supplierRepresentative = null,
        ?string $notes = null,
    ): GoodsReceipt {
        if ($items === []) {
            throw new DomainException('Penerimaan barang harus memiliki minimal satu item.');
        }

        return DB::transaction(function () use ($schedule, $items, $actor, $supplierRepresentative, $notes): GoodsReceipt {
            $schedule = DeliverySchedule::query()
                ->with(['purchaseOrder.kitchen', 'purchaseOrder.supplier'])
                ->lockForUpdate()
                ->findOrFail($schedule->getKey());

            if (! in_array($schedule->status, [
                DeliveryScheduleStatus::Confirmed,
                DeliveryScheduleStatus::InTransit,
                DeliveryScheduleStatus::Arrived,
                DeliveryScheduleStatus::PartiallyReceived,
            ], true)) {
                throw new DomainException('Barang tidak dapat diterima untuk jadwal pada status saat ini.');
            }

            $receipt = GoodsReceipt::query()->create([
                'number' => $this->documentNumbers->next('GR', $schedule->purchaseOrder->kitchen),
                'purchase_order_id' => $schedule->purchase_order_id,
                'delivery_schedule_id' => $schedule->getKey(),
                'supplier_id' => $schedule->purchaseOrder->supplier_id,
                'sppg_kitchen_id' => $schedule->purchaseOrder->sppg_kitchen_id,
                'received_at' => now(),
                'received_by' => $actor->getKey(),
                'supplier_representative' => $supplierRepresentative,
                'driver_name' => $schedule->driver_name,
                'vehicle_number' => $schedule->vehicle_number,
                'delivery_note_number' => $schedule->delivery_note_number,
                'status' => GoodsReceiptStatus::PendingInspection,
                'notes' => $notes,
            ]);

            foreach ($items as $scheduleItemId => $receivedValue) {
                $receivedQty = (float) $receivedValue;

                if ($receivedQty <= 0) {
                    throw new DomainException('Jumlah barang diterima harus lebih dari nol.');
                }

                $scheduleItem = DeliveryScheduleItem::query()
                    ->where('delivery_schedule_id', $schedule->getKey())
                    ->lockForUpdate()
                    ->findOrFail($scheduleItemId);

                $previouslyReceived = (float) GoodsReceiptItem::query()
                    ->where('delivery_schedule_item_id', $scheduleItem->getKey())
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', '!=', GoodsReceiptStatus::Cancelled->value))
                    ->sum('received_qty');

                $expectedForThisReceipt = max(0, (float) $scheduleItem->planned_qty - $previouslyReceived);

                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->getKey(),
                    'delivery_schedule_item_id' => $scheduleItem->getKey(),
                    'purchase_order_item_id' => $scheduleItem->purchase_order_item_id,
                    'planned_qty' => $expectedForThisReceipt,
                    'received_qty' => $receivedQty,
                    'accepted_qty' => 0,
                    'rejected_qty' => 0,
                    'variance_qty' => $receivedQty - $expectedForThisReceipt,
                ]);
            }

            $physicalReceived = (float) GoodsReceiptItem::query()
                ->whereHas('goodsReceipt', fn ($query) => $query
                    ->where('delivery_schedule_id', $schedule->getKey())
                    ->where('status', '!=', GoodsReceiptStatus::Cancelled->value))
                ->sum('received_qty');
            $planned = (float) $schedule->items()->sum('planned_qty');

            $schedule->update([
                'status' => $physicalReceived + 0.0001 >= $planned
                    ? DeliveryScheduleStatus::Received
                    : DeliveryScheduleStatus::PartiallyReceived,
            ]);

            if ($schedule->planned_delivery_at !== null && now()->greaterThan($schedule->planned_delivery_at)) {
                FulfillmentDiscrepancy::query()->firstOrCreate(
                    [
                        'purchase_order_id' => $schedule->purchase_order_id,
                        'goods_receipt_id' => $receipt->getKey(),
                        'type' => DiscrepancyType::LateDelivery->value,
                    ],
                    [
                        'description' => 'Pengiriman diterima melewati jadwal yang direncanakan.',
                        'status' => DiscrepancyStatus::Open,
                    ],
                );
            }

            return $receipt->load('items');
        }, 3);
    }
}
