<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
