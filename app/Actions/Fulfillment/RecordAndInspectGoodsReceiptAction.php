<?php

namespace App\Actions\Fulfillment;

use App\Enums\GoodsReceiptAttachmentType;
use App\Enums\OperationalProfile;
use App\Enums\SystemPermission;
use App\Models\DeliverySchedule;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordAndInspectGoodsReceiptAction
{
    public function __construct(
        private readonly RecordGoodsReceiptAction $recordGoodsReceipt,
        private readonly AddGoodsReceiptAttachmentAction $addAttachment,
        private readonly InspectGoodsReceiptAction $inspectGoodsReceipt,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $goodsPhotos
     * @param  array<int, string>  $weightPhotos
     */
    public function execute(
        DeliverySchedule $schedule,
        array $items,
        User $actor,
        ?string $supplierRepresentative = null,
        ?string $receiptNotes = null,
        array $goodsPhotos = [],
        array $weightPhotos = [],
    ): GoodsReceipt {
        $schedule->loadMissing('purchaseOrder.kitchen.organization');

        if ($schedule->purchaseOrder?->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Penerimaan dan QC satu langkah hanya tersedia untuk profil Lean.');
        }

        if (! $actor->can(SystemPermission::GoodsReceiptCreate->value)
            || ! $actor->can(SystemPermission::GoodsReceiptInspect->value)) {
            throw new DomainException('User harus memiliki izin penerimaan dan QC untuk aksi Lean ini.');
        }

        if ($items === []) {
            throw new DomainException('Minimal satu item harus diterima dan diperiksa.');
        }

        return DB::transaction(function () use (
            $schedule,
            $items,
            $actor,
            $supplierRepresentative,
            $receiptNotes,
            $goodsPhotos,
            $weightPhotos,
        ): GoodsReceipt {
            $received = [];
            $rows = [];

            foreach ($items as $row) {
                $scheduleItemId = (int) ($row['delivery_schedule_item_id'] ?? 0);
                $receivedQty = (float) ($row['received_qty'] ?? 0);
                $rejectedQty = (float) ($row['rejected_qty'] ?? 0);

                if ($scheduleItemId <= 0 || isset($received[$scheduleItemId])) {
                    throw new DomainException('Item penerimaan tidak valid atau duplikat.');
                }

                if ($receivedQty <= 0 || $rejectedQty < 0 || $rejectedQty - $receivedQty > 0.0001) {
                    throw new DomainException('Jumlah diterima/ditolak tidak valid.');
                }

                $received[$scheduleItemId] = $receivedQty;
                $rows[$scheduleItemId] = $row;
            }

            $receipt = $this->recordGoodsReceipt->execute(
                $schedule,
                $received,
                $actor,
                $supplierRepresentative,
                $receiptNotes,
            );

            foreach ($goodsPhotos as $path) {
                $this->addAttachment->execute(
                    $receipt,
                    GoodsReceiptAttachmentType::GoodsPhoto,
                    $path,
                    $actor,
                    null,
                    VendorFileStorage::DISK,
                );
            }

            foreach ($weightPhotos as $path) {
                $this->addAttachment->execute(
                    $receipt,
                    GoodsReceiptAttachmentType::WeightPhoto,
                    $path,
                    $actor,
                    null,
                    VendorFileStorage::DISK,
                );
            }

            $receipt->load('items');
            $inspection = [];

            foreach ($receipt->items as $receiptItem) {
                $row = $rows[(int) $receiptItem->delivery_schedule_item_id] ?? null;

                if ($row === null) {
                    throw new DomainException('Data QC tidak ditemukan untuk salah satu item penerimaan.');
                }

                $rejectedQty = (float) ($row['rejected_qty'] ?? 0);
                $receivedQty = (float) $receiptItem->received_qty;

                $inspection[$receiptItem->getKey()] = [
                    'accepted_qty' => $receivedQty - $rejectedQty,
                    'rejected_qty' => $rejectedQty,
                    'condition' => $row['condition'] ?? null,
                    'rejection_reason' => $row['rejection_reason'] ?? null,
                    'batch_number' => $row['batch_number'] ?? null,
                    'expiry_date' => $row['expiry_date'] ?? null,
                    'temperature' => $row['temperature'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ];
            }

            return $this->inspectGoodsReceipt->execute($receipt, $inspection, $actor);
        }, 3);
    }
}
