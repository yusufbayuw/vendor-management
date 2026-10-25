<?php

namespace App\Actions\Procurement;

use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderResponseType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderResponse;
use App\Models\User;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AcknowledgePurchaseOrderAction
{
    public function __construct(private readonly SupplierOperationalEligibilityService $eligibility) {}

    public function execute(PurchaseOrder $purchaseOrder, User $actor, ?string $notes = null): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $actor, $notes): PurchaseOrder {
            // Lock first, then check current status/identity. Stale or racing requests
            // cannot append a second response after the first acknowledgement.
            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->getKey());

            if ($lockedOrder->status !== PurchaseOrderStatus::Issued) {
                throw new DomainException('PO hanya dapat dikonfirmasi sekali setelah diterbitkan.');
            }

            $lockedOrder->load('kitchen.organization');
            $isLean = $lockedOrder->kitchen?->organization?->operational_profile === OperationalProfile::Lean;

            if (! $actor->is_active || ! (
                $actor->can(SystemPermission::PurchaseOrderAcknowledge->value)
                || ($isLean && $actor->can(SystemPermission::DeliveryManage->value))
            )) {
                throw new DomainException('User tidak berwenang mengonfirmasi PO supplier.');
            }

            if (! $actor->suppliers()
                ->whereKey($lockedOrder->supplier_id)
                ->wherePivot('is_active', true)
                ->exists()) {
                throw new DomainException('User bukan anggota aktif supplier pemilik PO.');
            }

            $supplier = $lockedOrder->supplier()
                ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->eligibility->isOperationallyEligible($supplier)) {
                throw new DomainException('Supplier tidak eligible untuk melakukan konfirmasi PO.');
            }

            PurchaseOrderResponse::query()->create([
                'purchase_order_id' => $lockedOrder->getKey(),
                'response' => PurchaseOrderResponseType::Accepted,
                'responded_by' => $actor->getKey(),
                'responded_at' => now(),
                'notes' => $notes,
            ]);

            $lockedOrder->forceFill([
                'status' => PurchaseOrderStatus::Acknowledged,
                'acknowledged_at' => now(),
            ])->save();

            return $lockedOrder->refresh();
        }, 3);
    }
}
