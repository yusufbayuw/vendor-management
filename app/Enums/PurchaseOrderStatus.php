<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Issued = 'issued';
    case Acknowledged = 'acknowledged';
    case Scheduled = 'scheduled';
    case PartiallyDelivered = 'partially_delivered';
    case Fulfilled = 'fulfilled';
    case PendingExceptionClosure = 'pending_exception_closure';
    case ClosedWithException = 'closed_with_exception';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
