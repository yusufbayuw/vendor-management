<?php

namespace App\Actions\Fulfillment;

use App\Enums\ApprovalDecisionSource;
use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Enums\SystemPermission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Governance\GovernancePolicyService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ClosePurchaseOrderWithExceptionAction
{
    public function __construct(
        private readonly RequestPurchaseOrderExceptionCloseAction $requestClose,
        private readonly ApprovePurchaseOrderExceptionCloseAction $approveClose,
        private readonly GovernancePolicyService $governancePolicyService,
    ) {}

    public function execute(PurchaseOrder $purchaseOrder, User $actor, string $reason): PurchaseOrder
    {
        if (blank($reason)) {
            throw new DomainException('Alasan penutupan dengan exception wajib diisi.');
        }

        $purchaseOrder->loadMissing('kitchen.organization');
        $organization = $purchaseOrder->kitchen->organization;

        if ($organization->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Aksi satu langkah close with exception hanya tersedia untuk profil Lean.');
        }

        if (! $actor->can(SystemPermission::PurchaseOrderExceptionClose->value)) {
            throw new DomainException('User tidak memiliki izin menutup PO dengan exception.');
        }

        $policy = $this->governancePolicyService->snapshot(
            $organization,
            GovernanceProcess::PurchaseOrderExceptionClosing,
            (float) $purchaseOrder->total_amount,
        );

        if (! $policy['self_approval_allowed'] || $policy['minimum_approvers'] !== 1) {
            throw new DomainException('Kebijakan PO ini tetap memerlukan approval close with exception secara terpisah.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $reason, $policy): PurchaseOrder {
            $purchaseOrder = $this->requestClose->execute($purchaseOrder, $actor, $reason);

            return $this->approveClose->execute(
                $purchaseOrder,
                $actor,
                $reason,
                $policy['requires_override_reason'] ? $reason : null,
                ApprovalDecisionSource::ExplicitSelfApproval,
            );
        }, 3);
    }
}
