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

    public function label(): string
    {
        return match ($this) {
            self::PurchaseRequestApproval => 'Approval Purchase Request',
            self::PurchaseOrderApproval => 'Approval Purchase Order',
            self::PurchaseOrderExceptionClosing => 'Penutupan PO dengan Exception',
            self::SupplierVerification => 'Verifikasi Supplier',
            self::SupplierBankAccountChange => 'Perubahan Rekening Supplier',
            self::InvoiceApproval => 'Approval Invoice',
            self::PaymentVerification => 'Verifikasi Pembayaran',
        };
    }
}
