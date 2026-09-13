<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;

class IssuePurchaseOrderAction
{
    public function execute(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Approved) {
            throw new DomainException('PO hanya dapat diterbitkan setelah disetujui.');
        }

        $purchaseOrder->forceFill([
            'status' => PurchaseOrderStatus::Issued,
            'issued_at' => now(),
        ])->save();

        return $purchaseOrder->refresh();
    }
}
