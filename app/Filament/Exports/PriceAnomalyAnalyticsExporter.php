<?php

namespace App\Filament\Exports;

use App\Models\PurchaseOrderItem;
use App\Services\Analytics\PriceAnomalyAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PriceAnomalyAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrderItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('purchaseOrder.order_date')->label('Tanggal PO'),
            ExportColumn::make('product_name_snapshot')->label('Komoditas'),
            ExportColumn::make('unit_name_snapshot')->label('Satuan'),
            ExportColumn::make('purchaseOrder.supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrderItem $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-')),
            ExportColumn::make('purchaseOrder.kitchen.name')->label('SPPG'),
            ExportColumn::make('unit_price')->label('Harga Satuan'),
            ExportColumn::make('anomaly_status')
                ->label('Status Anomaly')
                ->state(fn (PurchaseOrderItem $record): string => self::statusLabel(
                    self::metrics()->analysis($record)['status'],
                )),
            ExportColumn::make('baseline_count')
                ->label('Jumlah Baseline')
                ->state(fn (PurchaseOrderItem $record): int => self::metrics()->analysis($record)['baseline_count']),
            ExportColumn::make('baseline_median')
                ->label('Median Baseline')
                ->state(fn (PurchaseOrderItem $record): ?float => self::metrics()->analysis($record)['median']),
            ExportColumn::make('baseline_mad')
                ->label('MAD')
                ->state(fn (PurchaseOrderItem $record): ?float => self::metrics()->analysis($record)['mad']),
            ExportColumn::make('deviation_percentage')
                ->label('Deviasi dari Median (%)')
                ->state(fn (PurchaseOrderItem $record): ?float => self::metrics()->analysis($record)['deviation_percentage']),
            ExportColumn::make('robust_z')
                ->label('Robust Z-Score')
                ->state(fn (PurchaseOrderItem $record): ?float => self::metrics()->analysis($record)['robust_z']),
            ExportColumn::make('anomaly_reason')
                ->label('Penjelasan')
                ->state(fn (PurchaseOrderItem $record): string => self::metrics()->analysis($record)['reason']),
            ExportColumn::make('purchaseOrder.number')->label('Nomor PO'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export price anomaly analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): PriceAnomalyAnalyticsService
    {
        return app(PriceAnomalyAnalyticsService::class);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'high' => 'Anomaly Tinggi',
            'low' => 'Anomaly Rendah',
            'normal' => 'Normal',
            default => 'Baseline Belum Cukup',
        };
    }
}
