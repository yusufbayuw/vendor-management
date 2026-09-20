<?php

namespace App\Events;

class PurchaseRequestStatusChanged
{
    public function __construct(
        public readonly int $purchaseRequestId,
        public readonly string $status,
    ) {}
}
