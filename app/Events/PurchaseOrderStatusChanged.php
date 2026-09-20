<?php

namespace App\Events;

class PurchaseOrderStatusChanged
{
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly string $status,
    ) {}
}
