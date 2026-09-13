<?php

namespace App\Enums;

enum SupplierDocumentStatus: string
{
    case Uploaded = 'uploaded';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
