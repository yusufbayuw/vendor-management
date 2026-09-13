<?php

namespace App\Enums;

enum PurchaseAllocationStatus: string
{
    case Allocated = 'allocated';
    case PoGenerated = 'po_generated';
    case Cancelled = 'cancelled';
}
