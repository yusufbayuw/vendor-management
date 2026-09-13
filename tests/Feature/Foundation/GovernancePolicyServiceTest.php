<?php

namespace Tests\Feature\Foundation;

use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Models\GovernancePolicy;
use App\Models\Organization;
use App\Services\Governance\GovernancePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernancePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lean_profile_allows_self_approval_by_default(): void
    {
        $organization = Organization::query()->create([
            'code' => 'LEAN',
            'name' => 'Lean Organization',
            'operational_profile' => OperationalProfile::Lean,
        ]);

        $this->assertTrue(app(GovernancePolicyService::class)->selfApprovalAllowed(
            $organization,
            GovernanceProcess::PaymentVerification,
        ));
    }

    public function test_strict_profile_rejects_self_approval_by_default(): void
    {
        $organization = Organization::query()->create([
            'code' => 'STRICT',
            'name' => 'Strict Organization',
            'operational_profile' => OperationalProfile::Strict,
        ]);

        $this->assertFalse(app(GovernancePolicyService::class)->selfApprovalAllowed(
            $organization,
            GovernanceProcess::PurchaseRequestApproval,
        ));
    }

    public function test_explicit_policy_overrides_operational_profile(): void
    {
        $organization = Organization::query()->create([
            'code' => 'STD',
            'name' => 'Standard Organization',
            'operational_profile' => OperationalProfile::Standard,
        ]);

        GovernancePolicy::query()->create([
            'organization_id' => $organization->id,
            'process' => GovernanceProcess::PaymentVerification,
            'self_approval_allowed' => true,
        ]);

        $this->assertTrue(app(GovernancePolicyService::class)->selfApprovalAllowed(
            $organization,
            GovernanceProcess::PaymentVerification,
        ));
    }

    public function test_highest_matching_amount_threshold_wins(): void
    {
        $organization = Organization::query()->create([
            'code' => 'THRESHOLD',
            'name' => 'Threshold Organization',
            'operational_profile' => OperationalProfile::Lean,
        ]);

        GovernancePolicy::query()->create([
            'organization_id' => $organization->id,
            'process' => GovernanceProcess::PurchaseOrderApproval,
            'amount_threshold' => 10_000_000,
            'self_approval_allowed' => true,
        ]);

        GovernancePolicy::query()->create([
            'organization_id' => $organization->id,
            'process' => GovernanceProcess::PurchaseOrderApproval,
            'amount_threshold' => 100_000_000,
            'self_approval_allowed' => false,
        ]);

        $service = app(GovernancePolicyService::class);

        $this->assertTrue($service->selfApprovalAllowed($organization, GovernanceProcess::PurchaseOrderApproval, 50_000_000));
        $this->assertFalse($service->selfApprovalAllowed($organization, GovernanceProcess::PurchaseOrderApproval, 150_000_000));
    }
}
