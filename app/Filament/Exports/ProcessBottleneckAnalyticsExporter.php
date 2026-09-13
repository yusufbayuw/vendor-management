<?php

namespace App\Filament\Exports;

use App\Models\PurchaseOrder;
use App\Services\Analytics\ProcessBottleneckAnalyticsService;
use App\Services\Analytics\ProcessPerformanceAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ProcessBottleneckAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        $columns = [
            ExportColumn::make('order_date')->label('Tanggal PO'),
            ExportColumn::make('number')->label('Nomor PO'),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('status')->label('Status PO'),
            ExportColumn::make('bottleneck_status')
                ->label('Diagnostic Status')
                ->state(fn (PurchaseOrder $record): string => self::statusLabel(self::metrics()->overallStatus($record))),
            ExportColumn::make('bottleneck_stage')
                ->label('Bottleneck Stage')
                ->state(fn (PurchaseOrder $record): string => self::metrics()->bottleneckStage($record)),
            ExportColumn::make('slow_stage_count')
                ->label('Slow Stage Count')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->slowStageCount($record)),
            ExportColumn::make('evaluated_stage_count')
                ->label('Evaluated Stage Count')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->evaluatedStageCount($record)),
            ExportColumn::make('max_robust_z')
                ->label('Max Robust Z')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->maximumRobustZ($record)),
            ExportColumn::make('diagnostic_summary')
                ->label('Diagnostic Summary')
                ->state(fn (PurchaseOrder $record): string => self::metrics()->diagnosticSummary($record)),
            ExportColumn::make('longest_stage')
                ->label('Longest Completed Stage')
                ->state(fn (PurchaseOrder $record): string => self::performance()->longestCompletedStage($record)),
        ];

        foreach (self::metrics()->stageDefinitions() as $stage => $definition) {
            $label = $definition['label'];
            $columns[] = ExportColumn::make($stage.'_hours')
                ->label($label.' Hours')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->stageHours($record, $stage));
            $columns[] = ExportColumn::make($stage.'_status')
                ->label($label.' Status')
                ->state(fn (PurchaseOrder $record): string => self::stageStatusLabel(
                    self::metrics()->stageStatus($record, $stage),
                ));
            $columns[] = ExportColumn::make($stage.'_baseline_n')
                ->label($label.' Baseline N')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->stageBaselineCount($record, $stage));
            $columns[] = ExportColumn::make($stage.'_median_hours')
                ->label($label.' Median Hours')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->stageMedian($record, $stage));
            $columns[] = ExportColumn::make($stage.'_robust_z')
                ->label($label.' Robust Z')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->stageRobustZ($record, $stage));
        }

        return $columns;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export process bottleneck analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): ProcessBottleneckAnalyticsService
    {
        return app(ProcessBottleneckAnalyticsService::class);
    }

    private static function performance(): ProcessPerformanceAnalyticsService
    {
        return app(ProcessPerformanceAnalyticsService::class);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'bottleneck' => 'Bottleneck',
            'unusually_fast' => 'Unusually Fast',
            'normal' => 'Normal',
            'data_quality' => 'Data Quality Issue',
            default => 'Baseline Belum Cukup',
        };
    }

    private static function stageStatusLabel(string $status): string
    {
        return match ($status) {
            'slow' => 'Slow Anomaly',
            'fast' => 'Fast Anomaly',
            'normal' => 'Normal',
            'invalid' => 'Data Quality Issue',
            'incomplete' => 'Incomplete',
            default => 'Baseline Belum Cukup',
        };
    }
}
