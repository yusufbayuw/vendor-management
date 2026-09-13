<?php

namespace App\Actions\Procurement;

use App\Actions\Approval\RejectApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseRequestStatus;
use App\Models\ApprovalRequest;
use App\Models\PurchaseRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RejectPurchaseRequestAction
{
    public function __construct(private readonly RejectApprovalRequestAction $rejectApprovalRequest) {}

    public function execute(PurchaseRequest $purchaseRequest, User $actor, string $reason): PurchaseRequest
    {
        if (! in_array($purchaseRequest->status, [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview], true)) {
            throw new DomainException('Purchase request tidak sedang menunggu approval.');
        }

        return DB::transaction(function () use ($purchaseRequest, $actor, $reason): PurchaseRequest {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $purchaseRequest->getMorphClass())
                ->where('approvable_id', $purchaseRequest->getKey())
                ->where('process', GovernanceProcess::PurchaseRequestApproval->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $this->rejectApprovalRequest->execute($approvalRequest, $actor, $reason);

            $purchaseRequest->forceFill([
                'status' => PurchaseRequestStatus::Rejected,
                'rejected_at' => now(),
                'rejected_by' => $actor->getKey(),
                'rejection_reason' => $reason,
            ])->save();

            return $purchaseRequest->refresh();
        });
    }
}
