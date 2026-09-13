<?php

namespace App\Filament\Exports;

use App\Models\GoodsReceiptItem;
use App\Services\Analytics\DeliveryQualityAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DeliveryQualityAnalyticsExporter extends Exporter
{
    protected static ?string $model = GoodsReceiptItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('goodsReceipt.received_at')->label('Diterima Pada'),
            ExportColumn::make('goodsReceipt.kitchen.name')->label('SPPG'),
            ExportColumn::make('goodsReceipt.supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, GoodsReceiptItem $record): string => $state ?: ($record->goodsReceipt?->supplier?->legal_name ?? '-')),
            ExportColumn::make('purchaseOrderItem.product_name_snapshot')->label('Komoditas'),
            ExportColumn::make('purchaseOrderItem.unit_name_snapshot')->label('Satuan'),
            ExportColumn::make('planned_qty')->label('Planned Qty'),
            ExportColumn::make('received_qty')->label('Received Qty'),
            ExportColumn::make('accepted_qty')->label('Accepted Qty'),
            ExportColumn::make('rejected_qty')->label('Rejected Qty'),
            ExportColumn::make('variance_qty')->label('Variance Qty'),
            ExportColumn::make('acceptance_rate')
                ->label('Acceptance Rate (%)')
                ->state(fn (GoodsReceiptItem $record): ?float => app(DeliveryQualityAnalyticsService::class)->acceptanceRate($record)),
            ExportColumn::make('reject_rate')
                ->label('Reject Rate (%)')
                ->state(fn (GoodsReceiptItem $record): ?float => app(DeliveryQualityAnalyticsService::class)->rejectRate($record)),
            ExportColumn::make('variance_rate')
                ->label('Variance (%)')
                ->state(fn (GoodsReceiptItem $record): ?float => app(DeliveryQualityAnalyticsService::class)->varianceRate($record)),
            ExportColumn::make('condition')->label('Kondisi'),
            ExportColumn::make('rejection_reason')->label('Alasan Penolakan'),
            ExportColumn::make('temperature')->label('Suhu'),
            ExportColumn::make('batch_number')->label('Batch'),
            ExportColumn::make('expiry_date')->label('Kedaluwarsa'),
            ExportColumn::make('goodsReceipt.number')->label('Nomor Penerimaan'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export delivery & quality analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
