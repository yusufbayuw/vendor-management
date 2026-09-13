<?php

namespace App\Enums;

enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case PartiallyAllocated = 'partially_allocated';
    case FullyAllocated = 'fully_allocated';
    case PoGenerated = 'po_generated';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
}
