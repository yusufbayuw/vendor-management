<?php

namespace App\Enums;

enum LegacyImportType: string
{
    case Suppliers = 'suppliers';
    case PurchaseRequests = 'purchase_requests';
    case PurchaseOrders = 'purchase_orders';
    case GoodsReceipts = 'goods_receipts';
    case Invoices = 'invoices';
    case Payments = 'payments';

    public function label(): string
    {
        return match ($this) {
            self::Suppliers => 'Supplier existing',
            self::PurchaseRequests => 'Purchase Request',
            self::PurchaseOrders => 'Purchase Order',
            self::GoodsReceipts => 'Penerimaan Barang',
            self::Invoices => 'Invoice',
            self::Payments => 'Pembayaran',
        };
    }

    public function requiredColumns(): array
    {
        return match ($this) {
            self::Suppliers => ['code', 'legal_name'],
            self::PurchaseRequests => ['number', 'kitchen_code', 'status'],
            self::PurchaseOrders => ['number', 'supplier_code', 'kitchen_code', 'order_date', 'status'],
            self::GoodsReceipts => ['number', 'received_at', 'status'],
            self::Invoices => ['number', 'invoice_date', 'status', 'payable_amount'],
            self::Payments => ['number', 'payment_date', 'amount', 'payment_method', 'status'],
        };
    }
}
