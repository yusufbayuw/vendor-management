<?php

namespace App\Services\Governance;

use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Models\GovernancePolicy;
use App\Models\Organization;

class GovernancePolicyService
{
    public function resolve(
        Organization $organization,
        GovernanceProcess $process,
        ?float $amount = null,
    ): ?GovernancePolicy {
        return GovernancePolicy::query()
            ->where('organization_id', $organization->getKey())
            ->where('process', $process->value)
            ->where('is_active', true)
            ->when(
                $amount !== null,
                fn ($query) => $query->where(
                    fn ($thresholdQuery) => $thresholdQuery
                        ->whereNull('amount_threshold')
                        ->orWhere('amount_threshold', '<=', $amount),
                ),
                fn ($query) => $query->whereNull('amount_threshold'),
            )
            ->orderByRaw('CASE WHEN amount_threshold IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('amount_threshold')
            ->first();
    }

    /** @return array{self_approval_allowed: bool, minimum_approvers: int, requires_override_reason: bool} */
    public function snapshot(
        Organization $organization,
        GovernanceProcess $process,
        ?float $amount = null,
    ): array {
        $policy = $this->resolve($organization, $process, $amount);

        return [
            'self_approval_allowed' => $policy?->self_approval_allowed
                ?? $this->defaultSelfApprovalAllowed($organization->operational_profile, $process),
            'minimum_approvers' => max(1, $policy?->minimum_approvers ?? 1),
            'requires_override_reason' => $policy?->requires_override_reason ?? false,
        ];
    }

    public function selfApprovalAllowed(
        Organization $organization,
        GovernanceProcess $process,
        ?float $amount = null,
    ): bool {
        return $this->snapshot($organization, $process, $amount)['self_approval_allowed'];
    }

    private function defaultSelfApprovalAllowed(
        OperationalProfile $profile,
        GovernanceProcess $process,
    ): bool {
        return match ($profile) {
            OperationalProfile::Lean => true,
            OperationalProfile::Strict => false,
            OperationalProfile::Standard => ! in_array($process, [
                GovernanceProcess::SupplierBankAccountChange,
                GovernanceProcess::PaymentVerification,
            ], true),
        };
    }
}
