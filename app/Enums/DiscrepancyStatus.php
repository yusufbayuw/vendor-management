<?php

namespace App\Enums;

enum DiscrepancyStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Waived = 'waived';
}
