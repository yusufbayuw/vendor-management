<?php

namespace App\Services\Access;

use App\Enums\GovernanceProcess;
use App\Enums\SystemPermission;
use App\Models\ApprovalRequest;
use App\Models\User;
use DomainException;

class ApprovalDecisionAuthorizationService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function assertCanDecide(ApprovalRequest $request, User $actor): void
    {
        $permission = match ($request->process) {
            GovernanceProcess::PurchaseRequestApproval => SystemPermission::PurchaseRequestApprove,
            GovernanceProcess::PurchaseOrderApproval => SystemPermission::PurchaseOrderApprove,
            GovernanceProcess::PurchaseOrderExceptionClosing => SystemPermission::PurchaseOrderExceptionClose,
            GovernanceProcess::SupplierVerification => SystemPermission::SupplierVerify,
            GovernanceProcess::SupplierBankAccountChange => SystemPermission::SupplierVerify,
            GovernanceProcess::InvoiceApproval => SystemPermission::InvoiceApprove,
            GovernanceProcess::PaymentVerification => SystemPermission::PaymentVerify,
        };

        // Supplier verification and bank changes are organization-wide decisions.
        // Kitchen scope grants organization visibility, not governance authority.
        $canDecideInOrganization = match ($request->process) {
            GovernanceProcess::SupplierVerification,
            GovernanceProcess::SupplierBankAccountChange => $this->access->canManageOrganization($actor, (int) $request->organization_id),
            default => $this->access->canAccessOrganization($actor, (int) $request->organization_id),
        };

        if (! $actor->is_active || ! $actor->can($permission->value) || ! $canDecideInOrganization) {
            throw new DomainException('User tidak memiliki izin dan cakupan untuk memutuskan approval ini.');
        }

        $approvable = $request->approvable;
        $kitchenId = $approvable?->getAttribute('sppg_kitchen_id');
        if ($kitchenId === null && $approvable?->getAttribute('invoice_id') !== null) {
            $kitchenId = $approvable->invoice?->sppg_kitchen_id;
        }

        if ($kitchenId !== null && ! $this->access->canAccessKitchen($actor, (int) $kitchenId)) {
            throw new DomainException('Approval berada di luar cakupan dapur pengguna.');
        }
    }
}
