<?php

namespace App\Enums;

enum ApprovalActionType: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
