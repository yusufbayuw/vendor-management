<?php

namespace App\Filament\Exports;

use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Models\FulfillmentDiscrepancy;
use App\Services\Analytics\DiscrepancyAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DiscrepancyAnalyticsExporter extends Exporter
{
    protected static ?string $model = FulfillmentDiscrepancy::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('created_at')->label('Dibuat Pada'),
            ExportColumn::make('purchaseOrder.number')->label('Nomor PO'),
            ExportColumn::make('purchaseOrder.kitchen.name')->label('SPPG'),
            ExportColumn::make('purchaseOrder.supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, FulfillmentDiscrepancy $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-')),
            ExportColumn::make('purchaseOrderItem.product_name_snapshot')->label('Komoditas'),
            ExportColumn::make('purchaseOrderItem.unit_name_snapshot')->label('Satuan'),
            ExportColumn::make('type')
                ->label('Jenis')
                ->formatStateUsing(fn ($state): string => self::typeLabel($state)),
            ExportColumn::make('expected_qty')->label('Expected Qty'),
            ExportColumn::make('actual_qty')->label('Actual Qty'),
            ExportColumn::make('variance_qty')->label('Variance Qty'),
            ExportColumn::make('variance_rate')
                ->label('Variance (%)')
                ->state(fn (FulfillmentDiscrepancy $record): ?float => app(DiscrepancyAnalyticsService::class)->varianceRate($record)),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
            ExportColumn::make('resolution')
                ->label('Resolution')
                ->formatStateUsing(fn ($state): string => self::resolutionLabel($state)),
            ExportColumn::make('resolution_hours')
                ->label('Waktu Penyelesaian (jam)')
                ->state(fn (FulfillmentDiscrepancy $record): ?float => app(DiscrepancyAnalyticsService::class)->resolutionHours($record)),
            ExportColumn::make('resolution_notes')->label('Catatan Resolution'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export discrepancy analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function typeLabel($state): string
    {
        $type = $state instanceof DiscrepancyType ? $state : DiscrepancyType::tryFrom((string) $state);

        return match ($type) {
            DiscrepancyType::UnderDelivery => 'Under Delivery',
            DiscrepancyType::OverDelivery => 'Over Delivery',
            DiscrepancyType::RejectedGoods => 'Rejected Goods',
            DiscrepancyType::LateDelivery => 'Late Delivery',
            DiscrepancyType::MissingDelivery => 'Missing Delivery',
            DiscrepancyType::WrongProduct => 'Wrong Product',
            DiscrepancyType::QualityIssue => 'Quality Issue',
            default => (string) $state,
        };
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof DiscrepancyStatus ? $state : DiscrepancyStatus::tryFrom((string) $state);

        return match ($status) {
            DiscrepancyStatus::Open => 'Terbuka',
            DiscrepancyStatus::Resolved => 'Selesai',
            DiscrepancyStatus::Waived => 'Waived',
            default => (string) $state,
        };
    }

    private static function resolutionLabel($state): string
    {
        if ($state === null || $state === '') {
            return '-';
        }

        $resolution = $state instanceof DiscrepancyResolution ? $state : DiscrepancyResolution::tryFrom((string) $state);

        return match ($resolution) {
            DiscrepancyResolution::ReplacementRequired => 'Replacement Required',
            DiscrepancyResolution::RemainingCancelled => 'Remaining Cancelled',
            DiscrepancyResolution::AcceptedException => 'Accepted Exception',
            DiscrepancyResolution::FinancialAdjustment => 'Financial Adjustment',
            DiscrepancyResolution::Other => 'Other',
            default => (string) $state,
        };
    }
}
