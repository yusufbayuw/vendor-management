<?php

namespace App\Filament\Exports;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class SppgAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_date')->label('Tanggal PO'),
            ExportColumn::make('kitchen.organization.name')->label('Organisasi'),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('number')->label('Nomor PO'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('items_count')->label('Jumlah Item'),
            ExportColumn::make('total_amount')->label('Nilai PO'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
            ExportColumn::make('open_discrepancies_count')->label('Discrepancy Terbuka'),
            ExportColumn::make('invoice.payable_amount')->label('Payable Invoice'),
            ExportColumn::make('verified_payment_amount')
                ->label('Pembayaran Terverifikasi')
                ->state(fn (PurchaseOrder $record): float => self::verifiedPayment($record)),
            ExportColumn::make('outstanding_amount')
                ->label('Outstanding')
                ->state(fn (PurchaseOrder $record): float => max(
                    0,
                    (float) ($record->invoice?->payable_amount ?? 0) - self::verifiedPayment($record),
                )),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export analitik SPPG selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function verifiedPayment(PurchaseOrder $record): float
    {
        return (float) ($record->invoice?->payments
            ->where('status', PaymentStatus::Verified)
            ->sum('amount') ?? 0);
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
            PurchaseOrderStatus::PendingApproval => 'Menunggu Approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Diterbitkan',
            PurchaseOrderStatus::Acknowledged => 'Dikonfirmasi Supplier',
            PurchaseOrderStatus::Scheduled => 'Terjadwal',
            PurchaseOrderStatus::PartiallyDelivered => 'Terkirim Sebagian',
            PurchaseOrderStatus::Fulfilled => 'Terpenuhi',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu Penutupan Exception',
            PurchaseOrderStatus::ClosedWithException => 'Ditutup dengan Exception',
            PurchaseOrderStatus::Invoiced => 'Ditagihkan',
            PurchaseOrderStatus::Paid => 'Dibayar',
            PurchaseOrderStatus::Closed => 'Selesai',
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }
}
