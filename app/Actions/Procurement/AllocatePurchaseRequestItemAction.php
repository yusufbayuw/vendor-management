<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseAllocationStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AllocatePurchaseRequestItemAction
{
    public function __construct(
        private readonly RefreshPurchaseRequestAllocationStatusAction $refreshStatus,
        private readonly SupplierOperationalEligibilityService $supplierEligibility,
    ) {}

    public function execute(
        PurchaseRequestItem $item,
        Supplier $supplier,
        float $quantity,
        float $unitPrice,
        User $actor,
        ?string $notes = null,
    ): PurchaseAllocation {
        if ($quantity <= 0) {
            throw new DomainException('Jumlah alokasi harus lebih dari nol.');
        }

        if ($unitPrice < 0) {
            throw new DomainException('Harga satuan tidak boleh negatif.');
        }

        return DB::transaction(function () use ($item, $supplier, $quantity, $unitPrice, $actor, $notes): PurchaseAllocation {
            $lockedSupplier = Supplier::query()
                ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
                ->lockForUpdate()
                ->findOrFail($supplier->getKey());

            if (! $this->supplierEligibility->isOperationallyEligible($lockedSupplier)) {
                throw new DomainException('Supplier belum aktif secara operasional atau aktivasi supplier tidak valid.');
            }

            $lockedItem = PurchaseRequestItem::query()
                ->with('purchaseRequest')
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            if (! in_array($lockedItem->purchaseRequest->status, [
                PurchaseRequestStatus::Approved,
                PurchaseRequestStatus::PartiallyAllocated,
            ], true)) {
                throw new DomainException('Purchase request harus sudah disetujui dan belum selesai dialokasikan.');
            }

            $allocated = (float) PurchaseAllocation::query()
                ->where('purchase_request_item_id', $lockedItem->getKey())
                ->where('status', '!=', PurchaseAllocationStatus::Cancelled->value)
                ->sum('allocated_qty');

            $remaining = (float) $lockedItem->requested_qty - $allocated;

            if ($quantity - $remaining > 0.0001) {
                throw new DomainException(sprintf(
                    'Jumlah alokasi melebihi sisa kebutuhan. Sisa yang dapat dialokasikan: %.4f.',
                    max(0, $remaining),
                ));
            }

            $allocation = PurchaseAllocation::query()->create([
                'purchase_request_item_id' => $lockedItem->getKey(),
                'supplier_id' => $lockedSupplier->getKey(),
                'allocated_qty' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
                'status' => PurchaseAllocationStatus::Allocated,
                'notes' => $notes,
                'allocated_by' => $actor->getKey(),
                'allocated_at' => now(),
            ]);

            $this->refreshStatus->execute($lockedItem->purchaseRequest);

            return $allocation->refresh();
        }, 3);
    }
}
