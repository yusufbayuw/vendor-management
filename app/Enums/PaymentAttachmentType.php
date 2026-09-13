<?php

namespace App\Enums;

enum PaymentAttachmentType: string
{
    case PaymentProof = 'payment_proof';
    case BankStatement = 'bank_statement';
    case Other = 'other';
}
