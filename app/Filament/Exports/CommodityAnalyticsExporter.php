<?php

namespace App\Filament\Exports;

use App\Models\PurchaseOrderItem;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CommodityAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrderItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('purchaseOrder.order_date')
                ->label('Tanggal PO'),
            ExportColumn::make('purchaseOrder.kitchen.name')
                ->label('SPPG'),
            ExportColumn::make('product.category.name')
                ->label('Kategori'),
            ExportColumn::make('product_name_snapshot')
                ->label('Komoditas'),
            ExportColumn::make('purchaseOrder.supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrderItem $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-')),
            ExportColumn::make('ordered_qty')
                ->label('Dipesan'),
            ExportColumn::make('delivered_qty')
                ->label('Dikirim'),
            ExportColumn::make('accepted_qty')
                ->label('Diterima QC'),
            ExportColumn::make('rejected_qty')
                ->label('Ditolak QC'),
            ExportColumn::make('unit_name_snapshot')
                ->label('Satuan'),
            ExportColumn::make('unit_price')
                ->label('Harga Satuan'),
            ExportColumn::make('subtotal')
                ->label('Nilai'),
            ExportColumn::make('purchaseOrder.number')
                ->label('Nomor PO'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export analitik komoditas selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
