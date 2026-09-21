<?php

namespace App\Enums;

enum ApprovalDecisionSource: string
{
    case Manual = 'manual';
    case AutoSelfApproval = 'auto_self_approval';
}
