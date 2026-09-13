<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseOrderResponseType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderResponse;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class AcknowledgePurchaseOrderAction
{
    public function execute(PurchaseOrder $purchaseOrder, User $actor, ?string $notes = null): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Issued) {
            throw new DomainException('PO hanya dapat dikonfirmasi setelah diterbitkan.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $notes): PurchaseOrder {
            PurchaseOrderResponse::query()->create([
                'purchase_order_id' => $purchaseOrder->getKey(),
                'response' => PurchaseOrderResponseType::Accepted,
                'responded_by' => $actor->getKey(),
                'responded_at' => now(),
                'notes' => $notes,
            ]);

            $purchaseOrder->forceFill([
                'status' => PurchaseOrderStatus::Acknowledged,
                'acknowledged_at' => now(),
            ])->save();

            return $purchaseOrder->refresh();
        });
    }
}
