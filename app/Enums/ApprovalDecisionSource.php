<?php

namespace App\Enums;

enum ApprovalDecisionSource: string
{
    case Manual = 'manual';
    case AutoSelfApproval = 'auto_self_approval';
    case ExplicitSelfApproval = 'explicit_self_approval';
}
