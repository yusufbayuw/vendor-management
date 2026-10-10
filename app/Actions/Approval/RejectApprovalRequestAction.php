<?php

namespace App\Actions\Approval;

use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Access\ApprovalDecisionAuthorizationService;
use DomainException;
use Illuminate\Support\Facades\DB;

class RejectApprovalRequestAction
{
    public function __construct(private readonly ApprovalDecisionAuthorizationService $authorization) {}

    public function execute(ApprovalRequest $approvalRequest, User $actor, string $reason): ApprovalRequest
    {
        if (blank($reason)) {
            throw new DomainException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($approvalRequest, $actor, $reason): ApprovalRequest {
            $approvalRequest = ApprovalRequest::query()->lockForUpdate()->findOrFail($approvalRequest->getKey());

            $this->authorization->assertCanDecide($approvalRequest, $actor);

            if ($approvalRequest->status !== ApprovalStatus::Pending) {
                throw new DomainException('Approval ini sudah tidak menunggu keputusan.');
            }

            ApprovalAction::query()->create([
                'approval_request_id' => $approvalRequest->getKey(),
                'actor_id' => $actor->getKey(),
                'action' => ApprovalActionType::Rejected,
                'is_self_approval' => $approvalRequest->requested_by === $actor->getKey(),
                'comments' => $reason,
                'acted_at' => now(),
            ]);

            $approvalRequest->forceFill([
                'status' => ApprovalStatus::Rejected,
                'resolved_at' => now(),
            ])->save();

            return $approvalRequest->refresh();
        });
    }
}
