<?php

namespace App\Enums;

enum DeliveryScheduleStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
}
