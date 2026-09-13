<?php

namespace App\Enums;

enum PurchaseOrderResponseType: string
{
    case Accepted = 'accepted';
    case RevisionRequested = 'revision_requested';
    case Rejected = 'rejected';
}
