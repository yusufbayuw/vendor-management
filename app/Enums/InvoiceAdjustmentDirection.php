<?php

namespace App\Enums;

enum InvoiceAdjustmentDirection: string
{
    case Addition = 'addition';
    case Deduction = 'deduction';
}
