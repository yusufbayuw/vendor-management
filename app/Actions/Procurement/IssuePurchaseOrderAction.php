<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class IssuePurchaseOrderAction
{
    public function __construct(private readonly SupplierOperationalEligibilityService $supplierEligibility) {}

    public function execute(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->getKey());

            if ($lockedOrder->status !== PurchaseOrderStatus::Approved) {
                throw new DomainException('PO hanya dapat diterbitkan setelah disetujui.');
            }

            // An allocation or approval may predate supplier suspension.
            // Check again at issuance, the point a new order becomes actionable.
            $supplier = $lockedOrder->supplier()
                ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->supplierEligibility->isOperationallyEligible($supplier)) {
                throw new DomainException('PO tidak dapat diterbitkan karena supplier tidak aktif atau belum memenuhi syarat operasional.');
            }

            $lockedOrder->forceFill([
                'status' => PurchaseOrderStatus::Issued,
                'issued_at' => now(),
            ])->save();

            return $lockedOrder->refresh();
        }, 3);
    }
}
