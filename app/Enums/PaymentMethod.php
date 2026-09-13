<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case VirtualAccount = 'virtual_account';
    case Cash = 'cash';
    case Other = 'other';
}
