<?php

namespace App\Enums;

enum GoodsReceiptStatus: string
{
    case PendingInspection = 'pending_inspection';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
