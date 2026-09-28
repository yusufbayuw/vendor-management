<?php

namespace App\Events;

class DeliveryScheduleStatusChanged
{
    public function __construct(
        public readonly int $deliveryScheduleId,
        public readonly string $status,
    ) {}
}
