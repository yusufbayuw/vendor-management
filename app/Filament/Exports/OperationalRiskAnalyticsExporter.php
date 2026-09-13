<?php

namespace App\Filament\Exports;

use App\Models\PurchaseOrder;
use App\Services\Analytics\OperationalRiskAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class OperationalRiskAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_date')->label('Tanggal PO'),
            ExportColumn::make('number')->label('Nomor PO'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('status')
                ->label('Status PO')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state?->value ?? (string) $state)),
            ExportColumn::make('total_amount')->label('Nilai PO'),
            ExportColumn::make('risk_level')
                ->label('Risk Level')
                ->state(fn (PurchaseOrder $record): string => self::metrics()->riskLevel($record)),
            ExportColumn::make('risk_flag_count')
                ->label('Jumlah Risk Flag')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->riskFlagCount($record)),
            ExportColumn::make('risk_reasons')
                ->label('Alasan Risiko')
                ->state(fn (PurchaseOrder $record): string => self::metrics()->riskReasons($record)),
            ExportColumn::make('overdue_schedules')
                ->label('Jadwal Terlambat')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->overdueScheduleCount($record)),
            ExportColumn::make('open_discrepancies')
                ->label('Discrepancy Terbuka')
                ->state(fn (PurchaseOrder $record): int => self::metrics()->openDiscrepancyCount($record)),
            ExportColumn::make('fill_rate')
                ->label('Fill Rate (%)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->fillRate($record)),
            ExportColumn::make('reject_rate')
                ->label('Reject Rate (%)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->rejectRate($record)),
            ExportColumn::make('delivery_end')->label('Batas Akhir Delivery'),
            ExportColumn::make('invoice.due_date')->label('Jatuh Tempo Invoice'),
            ExportColumn::make('invoice_outstanding')
                ->label('Outstanding Invoice')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->invoiceOutstanding($record)),
            ExportColumn::make('invoice_overdue_days')
                ->label('Hari Lewat Jatuh Tempo')
                ->state(fn (PurchaseOrder $record): ?int => self::metrics()->invoiceOverdueDays($record)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export operational risk analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): OperationalRiskAnalyticsService
    {
        return app(OperationalRiskAnalyticsService::class);
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_approval' => 'Menunggu Approval',
            'approved' => 'Disetujui',
            'issued' => 'Diterbitkan',
            'acknowledged' => 'Diakui Supplier',
            'scheduled' => 'Terjadwal',
            'partially_delivered' => 'Terkirim Sebagian',
            'fulfilled' => 'Fulfilled',
            'pending_exception_closure' => 'Menunggu Exception Closure',
            'closed_with_exception' => 'Closed with Exception',
            'invoiced' => 'Invoiced',
            'paid' => 'Paid',
            'closed' => 'Closed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
