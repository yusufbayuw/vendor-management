<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseAllocationStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;

class RefreshPurchaseRequestAllocationStatusAction
{
    public function execute(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        $purchaseRequest->loadMissing('items');

        $hasAllocation = false;
        $fullyAllocated = $purchaseRequest->items->isNotEmpty();

        foreach ($purchaseRequest->items as $item) {
            $allocated = (float) $item->allocations()
                ->where('status', '!=', PurchaseAllocationStatus::Cancelled->value)
                ->sum('allocated_qty');
            $requested = (float) $item->requested_qty;

            $hasAllocation = $hasAllocation || $allocated > 0.0000001;
            $fullyAllocated = $fullyAllocated && abs($requested - $allocated) < 0.0001;
        }

        $status = $fullyAllocated
            ? PurchaseRequestStatus::FullyAllocated
            : ($hasAllocation ? PurchaseRequestStatus::PartiallyAllocated : PurchaseRequestStatus::Approved);

        $purchaseRequest->update(['status' => $status]);

        return $purchaseRequest->refresh();
    }
}
