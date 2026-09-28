<?php

namespace App\Enums;

enum BusinessFlowStage: string
{
    case PurchaseRequest = 'pr';
    case PurchaseOrder = 'po';
    case Delivery = 'delivery';
    case Receiving = 'receiving';
    case Invoice = 'invoice';
    case Payment = 'payment';

    public function order(): int
    {
        return match ($this) {
            self::PurchaseRequest => 1,
            self::PurchaseOrder => 2,
            self::Delivery => 3,
            self::Receiving => 4,
            self::Invoice => 5,
            self::Payment => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseRequest => 'PR',
            self::PurchaseOrder => 'PO',
            self::Delivery => 'Delivery',
            self::Receiving => 'Receiving',
            self::Invoice => 'Invoice',
            self::Payment => 'Payment',
        };
    }

    public function navigationLabel(): string
    {
        return $this->order().'. '.$this->label();
    }

    public function next(): ?self
    {
        return match ($this) {
            self::PurchaseRequest => self::PurchaseOrder,
            self::PurchaseOrder => self::Delivery,
            self::Delivery => self::Receiving,
            self::Receiving => self::Invoice,
            self::Invoice => self::Payment,
            self::Payment => null,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PurchaseRequest => 'Kebutuhan barang',
            self::PurchaseOrder => 'Pesanan ke supplier',
            self::Delivery => 'Pengiriman supplier',
            self::Receiving => 'Penerimaan dan QC',
            self::Invoice => 'Penagihan supplier',
            self::Payment => 'Pembayaran dan verifikasi',
        };
    }
}
