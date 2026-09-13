<?php

namespace App\Actions\Procurement;

use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseOrderForApprovalAction
{
    public function __construct(private readonly CreateApprovalRequestAction $createApprovalRequest) {}

    public function execute(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            throw new DomainException('Hanya PO draft yang dapat diajukan untuk approval.');
        }

        $purchaseOrder->loadMissing(['items', 'kitchen.organization']);

        if ($purchaseOrder->items->isEmpty()) {
            throw new DomainException('PO harus memiliki minimal satu item.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update(['status' => PurchaseOrderStatus::PendingApproval]);

            $this->createApprovalRequest->execute(
                $purchaseOrder,
                $purchaseOrder->kitchen->organization,
                GovernanceProcess::PurchaseOrderApproval,
                $actor,
                (float) $purchaseOrder->total_amount,
            );

            return $purchaseOrder->refresh();
        });
    }
}
