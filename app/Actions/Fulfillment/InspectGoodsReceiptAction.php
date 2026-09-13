<?php

namespace App\Actions\Fulfillment;

use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\GoodsReceiptAttachmentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class InspectGoodsReceiptAction
{
    /**
     * @param array<int, array{accepted_qty: float|int|string, rejected_qty: float|int|string, condition?: string|null, rejection_reason?: string|null, batch_number?: string|null, expiry_date?: mixed, temperature?: float|int|string|null, notes?: string|null}> $inspection
     */
    public function execute(GoodsReceipt $receipt, array $inspection, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $inspection, $actor): GoodsReceipt {
            $receipt = GoodsReceipt::query()
                ->with(['items.purchaseOrderItem.product.procurementRule', 'attachments', 'purchaseOrder.items'])
                ->lockForUpdate()
                ->findOrFail($receipt->getKey());

            if ($receipt->status !== GoodsReceiptStatus::PendingInspection) {
                throw new DomainException('Penerimaan barang ini sudah tidak menunggu pemeriksaan.');
            }

            foreach ($receipt->items as $item) {
                if (! array_key_exists($item->getKey(), $inspection)) {
                    throw new DomainException('Seluruh item penerimaan harus diperiksa sebelum diselesaikan.');
                }

                $data = $inspection[$item->getKey()];
                $accepted = (float) $data['accepted_qty'];
                $rejected = (float) $data['rejected_qty'];
                $received = (float) $item->received_qty;

                if ($accepted < 0 || $rejected < 0 || abs(($accepted + $rejected) - $received) > 0.0001) {
                    throw new DomainException('Jumlah diterima harus sama dengan jumlah diterima QC + jumlah ditolak.');
                }

                if ($rejected > 0 && blank($data['rejection_reason'] ?? null)) {
                    throw new DomainException('Alasan penolakan wajib diisi jika terdapat barang yang ditolak.');
                }

                $rule = $item->purchaseOrderItem->product->procurementRule;

                if ($rule?->requires_expiry_date && empty($data['expiry_date'])) {
                    throw new DomainException('Tanggal kedaluwarsa wajib dicatat untuk produk ini.');
                }

                if ($rule?->requires_batch_number && blank($data['batch_number'] ?? null)) {
                    throw new DomainException('Nomor batch wajib dicatat untuk produk ini.');
                }

                if ($rule?->requires_temperature && ($data['temperature'] ?? null) === null) {
                    throw new DomainException('Suhu barang wajib dicatat untuk produk ini.');
                }

                if ($rule?->requires_photo && ! $receipt->attachments->contains(
                    fn ($attachment) => in_array($attachment->type, [
                        GoodsReceiptAttachmentType::GoodsPhoto,
                        GoodsReceiptAttachmentType::DeliveryPhoto,
                        GoodsReceiptAttachmentType::HandoverPhoto,
                    ], true),
                )) {
                    throw new DomainException('Foto penerimaan barang wajib dilampirkan untuk produk ini.');
                }

                if ($rule?->requires_weight_photo && ! $receipt->attachments->contains(
                    fn ($attachment) => $attachment->type === GoodsReceiptAttachmentType::WeightPhoto,
                )) {
                    throw new DomainException('Foto hasil timbang wajib dilampirkan untuk produk ini.');
                }

                $item->forceFill([
                    'accepted_qty' => $accepted,
                    'rejected_qty' => $rejected,
                    'condition' => $data['condition'] ?? null,
                    'rejection_reason' => $data['rejection_reason'] ?? null,
                    'batch_number' => $data['batch_number'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'temperature' => $data['temperature'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ])->save();

                if ($rejected > 0) {
                    FulfillmentDiscrepancy::query()->firstOrCreate(
                        [
                            'goods_receipt_item_id' => $item->getKey(),
                            'type' => DiscrepancyType::RejectedGoods->value,
                        ],
                        [
                            'purchase_order_id' => $receipt->purchase_order_id,
                            'purchase_order_item_id' => $item->purchase_order_item_id,
                            'goods_receipt_id' => $receipt->getKey(),
                            'expected_qty' => $received,
                            'actual_qty' => $accepted,
                            'variance_qty' => -$rejected,
                            'description' => $data['rejection_reason'],
                            'status' => DiscrepancyStatus::Open,
                        ],
                    );
                }
            }

            $receipt->forceFill([
                'status' => GoodsReceiptStatus::Completed,
                'inspected_at' => now(),
                'inspected_by' => $actor->getKey(),
            ])->save();

            foreach ($receipt->items as $receiptItem) {
                $poItem = PurchaseOrderItem::query()->lockForUpdate()->findOrFail($receiptItem->purchase_order_item_id);

                $totals = GoodsReceiptItem::query()
                    ->where('purchase_order_item_id', $poItem->getKey())
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Completed->value))
                    ->selectRaw('COALESCE(SUM(received_qty), 0) as delivered, COALESCE(SUM(accepted_qty), 0) as accepted, COALESCE(SUM(rejected_qty), 0) as rejected')
                    ->first();

                $poItem->forceFill([
                    'delivered_qty' => $totals->delivered,
                    'accepted_qty' => $totals->accepted,
                    'rejected_qty' => $totals->rejected,
                ])->save();

                if ((float) $poItem->accepted_qty - (float) $poItem->ordered_qty > 0.0001) {
                    FulfillmentDiscrepancy::query()->firstOrCreate(
                        [
                            'purchase_order_item_id' => $poItem->getKey(),
                            'type' => DiscrepancyType::OverDelivery->value,
                            'status' => DiscrepancyStatus::Open->value,
                        ],
                        [
                            'purchase_order_id' => $receipt->purchase_order_id,
                            'goods_receipt_id' => $receipt->getKey(),
                            'goods_receipt_item_id' => $receiptItem->getKey(),
                            'expected_qty' => $poItem->ordered_qty,
                            'actual_qty' => $poItem->accepted_qty,
                            'variance_qty' => (float) $poItem->accepted_qty - (float) $poItem->ordered_qty,
                            'description' => 'Jumlah barang diterima melebihi jumlah pada PO.',
                        ],
                    );
                }
            }

            $purchaseOrder = $receipt->purchaseOrder()->with('items')->lockForUpdate()->firstOrFail();
            $allFulfilled = $purchaseOrder->items->every(
                fn ($poItem) => (float) $poItem->accepted_qty + 0.0001 >= (float) $poItem->ordered_qty,
            );

            $purchaseOrder->update([
                'status' => $allFulfilled
                    ? PurchaseOrderStatus::Fulfilled
                    : PurchaseOrderStatus::PartiallyDelivered,
            ]);

            return $receipt->refresh()->load(['items', 'attachments', 'discrepancies']);
        }, 3);
    }
}
