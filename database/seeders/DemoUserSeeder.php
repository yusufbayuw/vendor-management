<?php

namespace Database\Seeders;

use App\Enums\AccessScopeType;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAccessScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoUserSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        DB::transaction(function (): void {
            $standard = Organization::query()->where('code', 'YTB-DEMO')->firstOrFail();
            $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();

            $this->internalUser(
                'role.superadmin',
                'Super Admin Role Demo',
                'role.superadmin@example.test',
                '+628113000001',
                SystemRole::SuperAdmin,
                [[AccessScopeType::Global, 0]],
            );
            $this->internalUser(
                'role.panel',
                'Panel User Role Demo',
                'role.panel@example.test',
                '+628113000002',
                SystemRole::PanelUser,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $this->internalUser(
                'role.central',
                'Central Manager Role Demo',
                'role.central@example.test',
                '+628113000003',
                SystemRole::CentralManager,
                [[AccessScopeType::Global, 0]],
            );
            $this->internalUser(
                'role.masterdata',
                'Master Data Steward Role Demo',
                'role.masterdata@example.test',
                '+628113000014',
                SystemRole::MasterDataSteward,
                [[AccessScopeType::Global, 0]],
            );
            $this->internalUser(
                'role.sppg.manager',
                'SPPG Manager Role Demo',
                'role.sppg.manager@example.test',
                '+628113000004',
                SystemRole::SppgManager,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $this->internalUser(
                'role.requester',
                'Requester Role Demo',
                'role.requester@example.test',
                '+628113000005',
                SystemRole::Requester,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $this->internalUser(
                'role.procurement',
                'Procurement Role Demo',
                'role.procurement@example.test',
                '+628113000006',
                SystemRole::Procurement,
                [[AccessScopeType::Organization, $standard->getKey()]],
            );
            $this->internalUser(
                'role.procurement.manager',
                'Procurement Manager Role Demo',
                'role.procurement.manager@example.test',
                '+628113000007',
                SystemRole::ProcurementManager,
                [[AccessScopeType::Organization, $standard->getKey()]],
            );
            $this->internalUser(
                'role.receiver',
                'Receiver Role Demo',
                'role.receiver@example.test',
                '+628113000008',
                SystemRole::Receiver,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $this->internalUser(
                'role.qc',
                'Quality Control Role Demo',
                'role.qc@example.test',
                '+628113000009',
                SystemRole::QualityControl,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $this->internalUser(
                'role.finance',
                'Finance Role Demo',
                'role.finance@example.test',
                '+628113000010',
                SystemRole::Finance,
                [[AccessScopeType::Organization, $standard->getKey()]],
            );
            $this->internalUser(
                'role.finance.manager',
                'Finance Manager Role Demo',
                'role.finance.manager@example.test',
                '+628113000011',
                SystemRole::FinanceManager,
                [[AccessScopeType::Organization, $standard->getKey()]],
            );
            $this->internalUser(
                'role.auditor',
                'Auditor Role Demo',
                'role.auditor@example.test',
                '+628113000012',
                SystemRole::Auditor,
                [[AccessScopeType::Global, 0]],
            );

            $inactive = $this->internalUser(
                'role.inactive',
                'Inactive User Demo',
                'role.inactive@example.test',
                '+628113000013',
                SystemRole::PanelUser,
                [[AccessScopeType::SppgKitchen, $bandung->getKey()]],
            );
            $inactive->forceFill(['is_active' => false])->save();

            $this->seedSupplierUsers();
        }, 3);
    }

    /**
     * @param  array<int, array{0: AccessScopeType, 1: int}>  $scopes
     */
    private function internalUser(
        string $username,
        string $name,
        string $email,
        string $phone,
        SystemRole $role,
        array $scopes,
    ): User {
        $user = $this->user($username, $name, $email, $phone);
        $user->syncRoles([$role->value]);
        $this->replaceScopes($user, $scopes);

        return $user;
    }

    private function seedSupplierUsers(): void
    {
        $definitions = [
            'SUP-AYAM' => [
                ['vendor.ayam.admin', 'Admin Ayam Makmur', 'supplier.ayam.admin@example.test', '+628123000001', SystemRole::SupplierAdmin],
                ['vendor.ayam.operator', 'Operator Ayam Makmur', 'supplier.ayam.operator@example.test', '+628123000002', SystemRole::SupplierOperator],
            ],
            'SUP-TANI' => [
                ['vendor.tani.admin', 'Admin Tani Jaya', 'supplier.tani.admin@example.test', '+628123000003', SystemRole::SupplierAdmin],
                ['vendor.tani.operator', 'Operator Tani Jaya', 'supplier.tani.operator@example.test', '+628123000004', SystemRole::SupplierOperator],
            ],
            'SUP-PANGAN' => [
                ['vendor.pangan.admin', 'Admin Pangan Nusantara', 'supplier.pangan.admin@example.test', '+628123000005', SystemRole::SupplierAdmin],
                ['vendor.pangan.operator', 'Operator Pangan Nusantara', 'supplier.pangan.operator@example.test', '+628123000006', SystemRole::SupplierOperator],
            ],
            'SUP-PENDING' => [
                ['vendor.pending.operator', 'Operator Supplier Pending', 'supplier.pending.operator@example.test', '+628123000007', SystemRole::SupplierOperator],
            ],
            'SUP-SUSPENDED' => [
                ['vendor.suspended.admin', 'Admin Supplier Suspended', 'supplier.suspended.admin@example.test', '+628123000008', SystemRole::SupplierAdmin],
                ['vendor.suspended.operator', 'Operator Supplier Suspended', 'supplier.suspended.operator@example.test', '+628123000009', SystemRole::SupplierOperator],
            ],
        ];

        foreach ($definitions as $supplierCode => $accounts) {
            $supplier = Supplier::query()->where('code', $supplierCode)->firstOrFail();

            foreach ($accounts as [$username, $name, $email, $phone, $role]) {
                $user = $this->user($username, $name, $email, $phone);
                $user->syncRoles([$role->value]);
                $this->replaceScopes($user, [[AccessScopeType::Supplier, $supplier->getKey()]]);

                $supplier->users()->syncWithoutDetaching([
                    $user->getKey() => [
                        'is_owner' => false,
                        'is_active' => true,
                    ],
                ]);
            }
        }
    }

    private function user(string $username, string $name, string $email, string $phone): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'username' => $username,
                'name' => $name,
                'phone' => $phone,
                'password' => self::DEMO_PASSWORD,
                'is_active' => true,
            ],
        );

        $user->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ])->save();

        return $user;
    }

    /**
     * @param  array<int, array{0: AccessScopeType, 1: int}>  $scopes
     */
    private function replaceScopes(User $user, array $scopes): void
    {
        UserAccessScope::query()->where('user_id', $user->getKey())->delete();

        foreach ($scopes as $index => [$type, $scopeId]) {
            UserAccessScope::query()->create([
                'user_id' => $user->getKey(),
                'scope_type' => $type->value,
                'scope_id' => $scopeId,
                'is_primary' => $index === 0,
            ]);
        }
    }
}
