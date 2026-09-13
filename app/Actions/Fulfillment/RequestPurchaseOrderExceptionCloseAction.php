<?php

namespace App\Actions\Fulfillment;

use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseOrderStatus;
use App\Models\FulfillmentDiscrepancy;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RequestPurchaseOrderExceptionCloseAction
{
    public function __construct(private readonly CreateApprovalRequestAction $createApprovalRequest) {}

    public function execute(PurchaseOrder $purchaseOrder, User $actor, string $reason): PurchaseOrder
    {
        if (blank($reason)) {
            throw new DomainException('Alasan penutupan dengan exception wajib diisi.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $reason): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['items', 'kitchen.organization'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->getKey());

            if ($purchaseOrder->status !== PurchaseOrderStatus::PartiallyDelivered) {
                throw new DomainException('Close with exception hanya dapat diminta untuk PO yang sudah terkirim sebagian.');
            }

            foreach ($purchaseOrder->items as $item) {
                $ordered = (float) $item->ordered_qty;
                $accepted = (float) $item->accepted_qty;

                if ($accepted + 0.0001 < $ordered) {
                    FulfillmentDiscrepancy::query()->firstOrCreate(
                        [
                            'purchase_order_item_id' => $item->getKey(),
                            'type' => DiscrepancyType::UnderDelivery->value,
                            'status' => DiscrepancyStatus::Open->value,
                        ],
                        [
                            'purchase_order_id' => $purchaseOrder->getKey(),
                            'expected_qty' => $ordered,
                            'actual_qty' => $accepted,
                            'variance_qty' => $accepted - $ordered,
                            'description' => $reason,
                        ],
                    );
                }
            }

            $this->createApprovalRequest->execute(
                $purchaseOrder,
                $purchaseOrder->kitchen->organization,
                GovernanceProcess::PurchaseOrderExceptionClosing,
                $actor,
                (float) $purchaseOrder->total_amount,
            );

            $purchaseOrder->forceFill([
                'status' => PurchaseOrderStatus::PendingExceptionClosure,
                'notes' => trim(implode("\n", array_filter([$purchaseOrder->notes, 'Exception close: '.$reason]))),
            ])->save();

            return $purchaseOrder->refresh();
        }, 3);
    }
}
