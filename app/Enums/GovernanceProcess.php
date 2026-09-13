<?php

namespace App\Enums;

enum GovernanceProcess: string
{
    case PurchaseRequestApproval = 'purchase_request.approval';
    case PurchaseOrderApproval = 'purchase_order.approval';
    case PurchaseOrderExceptionClosing = 'purchase_order.exception_closing';
    case SupplierVerification = 'supplier.verification';
    case SupplierBankAccountChange = 'supplier.bank_account_change';
    case InvoiceApproval = 'invoice.approval';
    case PaymentVerification = 'payment.verification';
}
