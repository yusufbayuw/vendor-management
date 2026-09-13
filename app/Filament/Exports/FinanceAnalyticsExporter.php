<?php

namespace App\Filament\Exports;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Analytics\FinanceAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class FinanceAnalyticsExporter extends Exporter
{
    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('invoice_date')->label('Tanggal Invoice'),
            ExportColumn::make('due_date')->label('Jatuh Tempo'),
            ExportColumn::make('number')->label('Nomor Invoice'),
            ExportColumn::make('supplier_invoice_number')->label('Nomor Invoice Supplier'),
            ExportColumn::make('purchaseOrder.number')->label('Nomor PO'),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, Invoice $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
            ExportColumn::make('po_amount')->label('Nilai PO'),
            ExportColumn::make('adjustment_amount')->label('Adjustment'),
            ExportColumn::make('withholding_tax_amount')->label('Pajak Potong'),
            ExportColumn::make('payable_amount')->label('Payable'),
            ExportColumn::make('verified_paid_amount')
                ->label('Pembayaran Terverifikasi')
                ->state(fn (Invoice $record): float => app(FinanceAnalyticsService::class)->verifiedPaid($record)),
            ExportColumn::make('outstanding_amount')
                ->label('Outstanding')
                ->state(fn (Invoice $record): float => app(FinanceAnalyticsService::class)->outstanding($record)),
            ExportColumn::make('aging_bucket')
                ->label('Aging')
                ->state(fn (Invoice $record): string => app(FinanceAnalyticsService::class)->agingBucket($record)),
            ExportColumn::make('overdue_days')
                ->label('Hari Overdue')
                ->state(fn (Invoice $record): int => app(FinanceAnalyticsService::class)->overdueDays($record)),
            ExportColumn::make('payment_lead_days')
                ->label('Approval ke Pembayaran (hari)')
                ->state(fn (Invoice $record): ?float => app(FinanceAnalyticsService::class)->paymentLeadDays($record)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export finance analytics selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom((string) $state);

        return match ($status) {
            InvoiceStatus::Draft => 'Draft',
            InvoiceStatus::Submitted => 'Submitted',
            InvoiceStatus::UnderReview => 'Under Review',
            InvoiceStatus::Approved => 'Approved',
            InvoiceStatus::PartiallyPaid => 'Partially Paid',
            InvoiceStatus::Paid => 'Paid',
            InvoiceStatus::Rejected => 'Rejected',
            InvoiceStatus::Cancelled => 'Cancelled',
            default => (string) $state,
        };
    }
}
