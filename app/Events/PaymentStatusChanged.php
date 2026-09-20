<?php

namespace App\Events;

class PaymentStatusChanged
{
    public function __construct(
        public readonly int $paymentId,
        public readonly string $status,
    ) {}
}
