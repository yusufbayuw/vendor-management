<?php

namespace App\Events;

class SupplierStatusChanged
{
    public function __construct(
        public readonly int $supplierId,
        public readonly string $status,
    ) {}
}
