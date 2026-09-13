<?php

namespace Database\Seeders;

use App\Enums\SystemPermission;
use App\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (SystemRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->sync(SystemRole::SuperAdmin, SystemPermission::cases());
        $this->sync(SystemRole::PanelUser, []);

        $this->sync(SystemRole::CentralManager, [
            SystemPermission::OrganizationView,
            SystemPermission::OrganizationManage,
            SystemPermission::KitchenView,
            SystemPermission::KitchenManage,
            SystemPermission::UserView,
            SystemPermission::UserManage,
            SystemPermission::MasterDataView,
            SystemPermission::SupplierView,
            SystemPermission::SupplierVerify,
            SystemPermission::SupplierSuspend,
            SystemPermission::PurchaseRequestApprove,
            SystemPermission::PurchaseRequestAllocate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderExceptionClose,
            SystemPermission::InvoiceApprove,
            SystemPermission::PaymentVerify,
            SystemPermission::ReportsView,
            SystemPermission::AuditView,
        ]);

        $this->sync(SystemRole::SppgManager, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::SupplierView,
            SystemPermission::PurchaseRequestApprove,
            SystemPermission::PurchaseOrderExceptionClose,
            SystemPermission::ReportsView,
        ]);

        $this->sync(SystemRole::Requester, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::PurchaseRequestSubmit,
        ]);

        $this->sync(SystemRole::Procurement, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::SupplierView,
            SystemPermission::PurchaseRequestAllocate,
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderIssue,
            SystemPermission::DeliverySchedule,
        ]);

        $this->sync(SystemRole::ProcurementManager, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::SupplierView,
            SystemPermission::PurchaseRequestAllocate,
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderIssue,
            SystemPermission::PurchaseOrderAmendmentApprove,
            SystemPermission::PurchaseOrderExceptionClose,
            SystemPermission::DeliverySchedule,
            SystemPermission::ReportsView,
        ]);

        $this->sync(SystemRole::Receiver, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::GoodsReceiptCreate,
        ]);

        $this->sync(SystemRole::QualityControl, [
            SystemPermission::KitchenView,
            SystemPermission::MasterDataView,
            SystemPermission::GoodsReceiptInspect,
            SystemPermission::GoodsReceiptReject,
        ]);

        $this->sync(SystemRole::Finance, [
            SystemPermission::KitchenView,
            SystemPermission::SupplierView,
            SystemPermission::InvoiceReview,
            SystemPermission::PaymentCreate,
        ]);

        $this->sync(SystemRole::FinanceManager, [
            SystemPermission::KitchenView,
            SystemPermission::SupplierView,
            SystemPermission::InvoiceReview,
            SystemPermission::InvoiceApprove,
            SystemPermission::PaymentVerify,
            SystemPermission::ReportsView,
        ]);

        $this->sync(SystemRole::SupplierAdmin, [
            SystemPermission::SupplierProfileManage,
            SystemPermission::PurchaseOrderAcknowledge,
            SystemPermission::DeliveryManage,
            SystemPermission::InvoiceSubmit,
        ]);

        $this->sync(SystemRole::SupplierOperator, [
            SystemPermission::PurchaseOrderAcknowledge,
            SystemPermission::DeliveryManage,
            SystemPermission::InvoiceSubmit,
        ]);

        $this->sync(SystemRole::Auditor, [
            SystemPermission::OrganizationView,
            SystemPermission::KitchenView,
            SystemPermission::UserView,
            SystemPermission::MasterDataView,
            SystemPermission::SupplierView,
            SystemPermission::AuditView,
            SystemPermission::ReportsView,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param array<SystemPermission> $permissions */
    private function sync(SystemRole $role, array $permissions): void
    {
        Role::findByName($role->value, 'web')->syncPermissions(
            array_map(static fn (SystemPermission $permission): string => $permission->value, $permissions),
        );
    }
}
