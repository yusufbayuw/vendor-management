<?php

namespace App\Actions\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Governance\GovernancePolicyService;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class CreateApprovalRequestAction
{
    public function __construct(private readonly GovernancePolicyService $governancePolicyService) {}

    public function execute(
        Model $approvable,
        Organization $organization,
        GovernanceProcess $process,
        User $requester,
        ?float $amount = null,
    ): ApprovalRequest {
        $existing = ApprovalRequest::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('process', $process->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->exists();

        if ($existing) {
            throw new DomainException('Masih ada approval yang menunggu keputusan untuk transaksi ini.');
        }

        $policy = $this->governancePolicyService->snapshot($organization, $process, $amount);

        return ApprovalRequest::query()->create([
            'organization_id' => $organization->getKey(),
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'process' => $process,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $requester->getKey(),
            'amount' => $amount,
            'required_approvers' => $policy['minimum_approvers'],
            'self_approval_allowed' => $policy['self_approval_allowed'],
            'requires_override_reason' => $policy['requires_override_reason'],
            'requested_at' => now(),
        ]);
    }
}
