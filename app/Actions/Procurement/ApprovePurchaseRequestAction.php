<?php

namespace App\Actions\Procurement;

use App\Actions\Approval\ApproveApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseRequestStatus;
use App\Models\ApprovalRequest;
use App\Models\PurchaseRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseRequestAction
{
    public function __construct(private readonly ApproveApprovalRequestAction $approveApprovalRequest) {}

    public function execute(
        PurchaseRequest $purchaseRequest,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): PurchaseRequest {
        if (! in_array($purchaseRequest->status, [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview], true)) {
            throw new DomainException('Purchase request tidak sedang menunggu approval.');
        }

        return DB::transaction(function () use ($purchaseRequest, $actor, $comments, $overrideReason): PurchaseRequest {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $purchaseRequest->getMorphClass())
                ->where('approvable_id', $purchaseRequest->getKey())
                ->where('process', GovernanceProcess::PurchaseRequestApproval->value)
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
                $purchaseRequest->forceFill([
                    'status' => PurchaseRequestStatus::Approved,
                    'approved_at' => now(),
                    'approved_by' => $actor->getKey(),
                ])->save();
            } else {
                $purchaseRequest->update(['status' => PurchaseRequestStatus::UnderReview]);
            }

            return $purchaseRequest->refresh();
        });
    }
}
