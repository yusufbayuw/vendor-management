<?php

namespace App\Events;

class GoodsReceiptStatusChanged
{
    public function __construct(
        public readonly int $goodsReceiptId,
        public readonly string $status,
    ) {}
}
