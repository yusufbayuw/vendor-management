<?php

namespace App\Enums;

enum DiscrepancyResolution: string
{
    case ReplacementRequired = 'replacement_required';
    case RemainingCancelled = 'remaining_cancelled';
    case AcceptedException = 'accepted_exception';
    case FinancialAdjustment = 'financial_adjustment';
    case Other = 'other';
}
