<?php

namespace App\Actions\Procurement;

use App\Actions\Approval\ApproveApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseOrderStatus;
use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrderAction
{
    public function __construct(private readonly ApproveApprovalRequestAction $approveApprovalRequest) {}

    public function execute(
        PurchaseOrder $purchaseOrder,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): PurchaseOrder {
        if ($purchaseOrder->status !== PurchaseOrderStatus::PendingApproval) {
            throw new DomainException('PO tidak sedang menunggu approval.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $comments, $overrideReason): PurchaseOrder {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $purchaseOrder->getMorphClass())
                ->where('approvable_id', $purchaseOrder->getKey())
                ->where('process', GovernanceProcess::PurchaseOrderApproval->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $approvalRequest = $this->approveApprovalRequest->execute(
                $approvalRequest,
                $actor,
                $comments,
                $overrideReason,
            );

            if ($approvalRequest->status === ApprovalStatus::Approved) {
                $purchaseOrder->forceFill([
                    'status' => PurchaseOrderStatus::Approved,
                    'approved_at' => now(),
                    'approved_by' => $actor->getKey(),
                ])->save();
            }

            return $purchaseOrder->refresh();
        });
    }
}
