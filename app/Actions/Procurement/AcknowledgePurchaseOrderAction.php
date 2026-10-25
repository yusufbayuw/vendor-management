<?php

namespace App\Actions\Procurement;

use App\Enums\OperationalProfile;
use App\Enums\PurchaseOrderResponseType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Enums\SystemRole;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderResponse;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AcknowledgePurchaseOrderAction
{
    public function __construct(
        private readonly SupplierOperationalEligibilityService $eligibility,
        private readonly UserAccessService $access,
    ) {}

    /** Supplier portal only: the actor must belong to this exact supplier. */
    public function execute(PurchaseOrder $purchaseOrder, User $actor, ?string $notes = null): PurchaseOrder
    {
        return $this->acknowledge($purchaseOrder, $actor, $notes, false);
    }

    /** Explicit back-office acknowledgement: no supplier membership is implied or fabricated. */
    public function executeInternal(PurchaseOrder $purchaseOrder, User $actor, ?string $notes = null): PurchaseOrder
    {
        return $this->acknowledge($purchaseOrder, $actor, $notes, true);
    }

    /**
     * Lean schedules may imply acknowledgement. The supplier path retains
     * membership checks; an internal operator requires scoped DeliveryManage.
     */
    public function executeLean(PurchaseOrder $purchaseOrder, User $actor, ?string $notes = null): PurchaseOrder
    {
        return $this->acknowledge($purchaseOrder, $actor, $notes, null);
    }

    private function acknowledge(PurchaseOrder $purchaseOrder, User $actor, ?string $notes, ?bool $internal): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $actor, $notes, $internal): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->getKey());

            if ($order->status !== PurchaseOrderStatus::Issued) {
                throw new DomainException('PO hanya dapat dikonfirmasi sekali setelah diterbitkan.');
            }

            $order->load('kitchen.organization');
            $isLean = $order->kitchen?->organization?->operational_profile === OperationalProfile::Lean;
            $isSupplierMember = $actor->suppliers()
                ->whereKey($order->supplier_id)
                ->wherePivot('is_active', true)
                ->exists();

            if (! $actor->is_active) {
                throw new DomainException('Akun pengguna tidak aktif.');
            }

            if ($internal === null) {
                if (! $isLean) {
                    throw new DomainException('Konfirmasi otomatis hanya tersedia untuk profil Lean.');
                }

                // Supplier accounts cannot impersonate back-office operators,
                // including after a membership is revoked or for another supplier.
                $internal = $isSupplierMember === false;
                $permission = SystemPermission::DeliveryManage;
            } else {
                $permission = SystemPermission::PurchaseOrderAcknowledge;
            }

            if (! $actor->can($permission->value)) {
                throw new DomainException('User tidak berwenang mengonfirmasi PO.');
            }

            if ($internal) {
                if ($actor->hasAnyRole([SystemRole::SupplierAdmin->value, SystemRole::SupplierOperator->value])
                    || $actor->suppliers()->wherePivot('is_active', true)->exists()) {
                    throw new DomainException('Akun supplier tidak dapat mengakses jalur konfirmasi internal.');
                }

                if (! $this->access->canAccessKitchen($actor, (int) $order->sppg_kitchen_id)) {
                    throw new DomainException('User tidak memiliki scope untuk dapur pada PO ini.');
                }
            } elseif (! $isSupplierMember) {
                throw new DomainException('User bukan anggota aktif supplier pemilik PO.');
            }

            $supplier = $order->supplier()
                ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->eligibility->isOperationallyEligible($supplier)) {
                throw new DomainException('Supplier tidak eligible untuk melakukan konfirmasi PO.');
            }

            PurchaseOrderResponse::query()->create([
                'purchase_order_id' => $order->getKey(),
                'response' => PurchaseOrderResponseType::Accepted,
                'responded_by' => $actor->getKey(),
                'responded_at' => now(),
                'notes' => $notes,
            ]);

            $order->forceFill([
                'status' => PurchaseOrderStatus::Acknowledged,
                'acknowledged_at' => now(),
            ])->save();

            return $order->refresh();
        }, 3);
    }
}
