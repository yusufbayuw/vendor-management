<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccessScopeType;
use App\Enums\SystemRole;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_system_role_has_at_least_one_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (SystemRole::cases() as $role) {
            $this->assertTrue(
                User::role($role->value)->exists(),
                "Role {$role->value} tidak memiliki akun demo.",
            );
        }
    }

    public function test_role_isolated_internal_users_have_expected_scope(): void
    {
        $this->seed(DatabaseSeeder::class);

        $requester = User::query()->where('email', 'role.requester@example.test')->firstOrFail();
        $this->assertTrue($requester->hasRole(SystemRole::Requester->value));
        $this->assertCount(1, $requester->roles);
        $this->assertTrue($requester->accessScopes()
            ->where('scope_type', AccessScopeType::SppgKitchen->value)
            ->exists());

        $procurement = User::query()->where('email', 'role.procurement@example.test')->firstOrFail();
        $this->assertTrue($procurement->hasRole(SystemRole::Procurement->value));
        $this->assertCount(1, $procurement->roles);
        $this->assertTrue($procurement->accessScopes()
            ->where('scope_type', AccessScopeType::Organization->value)
            ->exists());

        $central = User::query()->where('email', 'role.central@example.test')->firstOrFail();
        $this->assertTrue($central->hasRole(SystemRole::CentralManager->value));
        $this->assertTrue($central->accessScopes()
            ->where('scope_type', AccessScopeType::Global->value)
            ->where('scope_id', 0)
            ->exists());
    }

    public function test_supplier_admin_and_operator_are_isolated_and_scoped_to_their_supplier(): void
    {
        $this->seed(DatabaseSeeder::class);

        $supplier = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();
        $admin = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();
        $operator = User::query()->where('email', 'supplier.ayam.operator@example.test')->firstOrFail();

        $this->assertTrue($admin->hasRole(SystemRole::SupplierAdmin->value));
        $this->assertFalse($admin->hasRole(SystemRole::SupplierOperator->value));
        $this->assertTrue($operator->hasRole(SystemRole::SupplierOperator->value));
        $this->assertFalse($operator->hasRole(SystemRole::SupplierAdmin->value));

        foreach ([$admin, $operator] as $user) {
            $this->assertTrue($user->accessScopes()
                ->where('scope_type', AccessScopeType::Supplier->value)
                ->where('scope_id', $supplier->getKey())
                ->exists());
            $this->assertTrue($supplier->users()->whereKey($user->getKey())->exists());
        }
    }

    public function test_inactive_demo_user_is_available_for_access_control_testing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $inactive = User::query()->where('email', 'role.inactive@example.test')->firstOrFail();

        $this->assertFalse($inactive->is_active);
        $this->assertTrue($inactive->hasRole(SystemRole::PanelUser->value));
    }
}
