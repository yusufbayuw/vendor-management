<?php

namespace App\Filament\Exports;

use App\Models\PurchaseOrder;
use App\Services\Analytics\SupplierPerformanceAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SupplierPerformanceAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_date')->label('Tanggal PO'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('number')->label('Nomor PO'),
            ExportColumn::make('total_amount')->label('Nilai PO'),
            ExportColumn::make('evaluated_delivery_count')
                ->label('Pengiriman Dievaluasi')
                ->state(fn (PurchaseOrder $record): int => app(SupplierPerformanceAnalyticsService::class)->evaluatedDeliveryCount($record)),
            ExportColumn::make('fill_rate')
                ->label('Fill Rate (%)')
                ->state(fn (PurchaseOrder $record): ?float => app(SupplierPerformanceAnalyticsService::class)->fillRate($record)),
            ExportColumn::make('reject_rate')
                ->label('Reject Rate (%)')
                ->state(fn (PurchaseOrder $record): ?float => app(SupplierPerformanceAnalyticsService::class)->rejectRate($record)),
            ExportColumn::make('on_time_rate')
                ->label('On Time (%)')
                ->state(fn (PurchaseOrder $record): ?float => app(SupplierPerformanceAnalyticsService::class)->onTimeRate($record)),
            ExportColumn::make('in_full_rate')
                ->label('In Full (%)')
                ->state(fn (PurchaseOrder $record): ?float => app(SupplierPerformanceAnalyticsService::class)->inFullRate($record)),
            ExportColumn::make('otif_rate')
                ->label('OTIF (%)')
                ->state(fn (PurchaseOrder $record): ?float => app(SupplierPerformanceAnalyticsService::class)->otifRate($record)),
            ExportColumn::make('discrepancies_count')->label('Jumlah Discrepancy'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export supplier performance selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }
}
