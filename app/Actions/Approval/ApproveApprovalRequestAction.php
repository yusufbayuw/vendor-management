<?php

namespace App\Actions\Approval;

use App\Enums\ApprovalActionType;
use App\Enums\ApprovalDecisionSource;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveApprovalRequestAction
{
    public function execute(
        ApprovalRequest $approvalRequest,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
        ApprovalDecisionSource $decisionSource = ApprovalDecisionSource::Manual,
    ): ApprovalRequest {
        return DB::transaction(function () use ($approvalRequest, $actor, $comments, $overrideReason, $decisionSource): ApprovalRequest {
            $approvalRequest = ApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->getKey());

            if ($approvalRequest->status !== ApprovalStatus::Pending) {
                throw new DomainException('Approval ini sudah tidak menunggu keputusan.');
            }

            $isSelfApproval = $approvalRequest->requested_by === $actor->getKey();

            if ($isSelfApproval && ! $approvalRequest->self_approval_allowed) {
                throw new DomainException('Self approval tidak diperbolehkan untuk proses ini.');
            }

            if ($isSelfApproval && $approvalRequest->requires_override_reason && blank($overrideReason)) {
                throw new DomainException('Alasan override wajib diisi untuk self approval ini.');
            }

            if ($approvalRequest->actions()->where('actor_id', $actor->getKey())->exists()) {
                throw new DomainException('User ini sudah memberikan keputusan pada approval tersebut.');
            }

            ApprovalAction::query()->create([
                'approval_request_id' => $approvalRequest->getKey(),
                'actor_id' => $actor->getKey(),
                'action' => ApprovalActionType::Approved,
                'is_self_approval' => $isSelfApproval,
                'decision_source' => $decisionSource,
                'comments' => $comments,
                'override_reason' => $overrideReason,
                'acted_at' => now(),
            ]);

            $approvedCount = $approvalRequest->actions()
                ->where('action', ApprovalActionType::Approved->value)
                ->distinct('actor_id')
                ->count('actor_id');

            if ($approvedCount >= $approvalRequest->required_approvers) {
                $approvalRequest->forceFill([
                    'status' => ApprovalStatus::Approved,
                    'resolved_at' => now(),
                ])->save();
            }

            return $approvalRequest->refresh();
        });
    }
}
