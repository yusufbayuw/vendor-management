<?php

namespace App\Actions\Approval;

use App\Enums\ApprovalDecisionSource;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Models\ApprovalRequest;
use App\Models\User;

class AutoSelfApproveApprovalRequestAction
{
    public function __construct(
        private readonly ApproveApprovalRequestAction $approveApprovalRequest,
    ) {}

    public function execute(ApprovalRequest $approvalRequest, User $actor): ApprovalRequest
    {
        $approvalRequest->loadMissing('organization');

        if (! $this->eligible($approvalRequest, $actor)) {
            return $approvalRequest;
        }

        return $this->approveApprovalRequest->execute(
            $approvalRequest,
            $actor,
            'Disetujui otomatis oleh profil Lean untuk single operator.',
            null,
            ApprovalDecisionSource::AutoSelfApproval,
        );
    }

    private function eligible(ApprovalRequest $approvalRequest, User $actor): bool
    {
        if (
            $approvalRequest->status !== ApprovalStatus::Pending
            || $approvalRequest->organization->operational_profile !== OperationalProfile::Lean
            || $approvalRequest->requested_by !== $actor->getKey()
            || ! $approvalRequest->self_approval_allowed
            || $approvalRequest->required_approvers !== 1
            || $approvalRequest->requires_override_reason
        ) {
            return false;
        }

        return in_array($approvalRequest->process, [
            GovernanceProcess::PurchaseRequestApproval,
            GovernanceProcess::PurchaseOrderApproval,
            GovernanceProcess::InvoiceApproval,
        ], true);
    }
}
