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

    public function selfApprovalAllowed(
        Organization $organization,
        GovernanceProcess $process,
        ?float $amount = null,
    ): bool {
        $policy = $this->resolve($organization, $process, $amount);

        if ($policy !== null) {
            return $policy->self_approval_allowed;
        }

        return $this->defaultSelfApprovalAllowed($organization->operational_profile, $process);
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
