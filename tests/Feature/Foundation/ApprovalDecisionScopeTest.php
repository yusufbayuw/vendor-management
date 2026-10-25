<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccessScopeType;
use App\Enums\GovernanceProcess;
use App\Enums\SystemRole;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Services\Access\ApprovalDecisionAuthorizationService;
use App\Services\Access\UserAccessService;
use Database\Seeders\RoleAndPermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalDecisionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_scope_does_not_authorize_organization_wide_supplier_decisions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $organization = Organization::query()->create(['code' => 'ORG-APPROVAL', 'name' => 'Organization Approval']);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'KIT-APPROVAL',
            'name' => 'Kitchen Approval',
        ]);
        $supplier = Supplier::query()->create(['code' => 'SUP-APPROVAL', 'legal_name' => 'Supplier Approval']);
        $bank = SupplierBankAccount::query()->create([
            'supplier_id' => $supplier->getKey(),
            'bank_name' => 'Bank Test',
            'account_number' => '1234567890',
            'account_holder' => 'Supplier Approval',
        ]);

        $kitchenActor = User::factory()->create();
        $kitchenActor->assignRole(SystemRole::CentralManager->value);
        UserAccessScope::query()->create([
            'user_id' => $kitchenActor->getKey(),
            'scope_type' => AccessScopeType::SppgKitchen,
            'scope_id' => $kitchen->getKey(),
        ]);

        $organizationActor = User::factory()->create();
        $organizationActor->assignRole(SystemRole::CentralManager->value);
        UserAccessScope::query()->create([
            'user_id' => $organizationActor->getKey(),
            'scope_type' => AccessScopeType::Organization,
            'scope_id' => $organization->getKey(),
        ]);

        $access = app(UserAccessService::class);
        $decisions = app(ApprovalDecisionAuthorizationService::class);

        // Kitchen scope can see its parent organization, but cannot govern it.
        $this->assertTrue($access->canAccessOrganization($kitchenActor, $organization->getKey()));
        $this->assertFalse($access->canManageOrganization($kitchenActor, $organization->getKey()));

        foreach ([
            [GovernanceProcess::SupplierVerification, $supplier],
            [GovernanceProcess::SupplierBankAccountChange, $bank],
        ] as [$process, $approvable]) {
            $approval = ApprovalRequest::query()->create([
                'organization_id' => $organization->getKey(),
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'process' => $process,
                'requested_by' => $organizationActor->getKey(),
                'requested_at' => now(),
            ]);

            try {
                $decisions->assertCanDecide($approval, $kitchenActor);
                $this->fail('Kitchen-scoped user approved an organization-wide supplier decision.');
            } catch (DomainException $exception) {
                $this->assertSame(
                    'User tidak memiliki izin dan cakupan untuk memutuskan approval ini.',
                    $exception->getMessage(),
                );
            }

            // Preserve legitimate organization-scoped governance access.
            $decisions->assertCanDecide($approval, $organizationActor);
        }
    }
}
