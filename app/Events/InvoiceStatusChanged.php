<?php

namespace App\Events;

class InvoiceStatusChanged
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $status,
    ) {}
}
