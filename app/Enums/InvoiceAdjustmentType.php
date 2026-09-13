<?php

namespace App\Enums;

enum InvoiceAdjustmentType: string
{
    case Penalty = 'penalty';
    case Correction = 'correction';
    case Credit = 'credit';
    case TaxCorrection = 'tax_correction';
    case Other = 'other';
}
