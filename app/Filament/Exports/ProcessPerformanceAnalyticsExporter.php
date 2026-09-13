<?php

namespace App\Filament\Exports;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Analytics\ProcessPerformanceAnalyticsService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ProcessPerformanceAnalyticsExporter extends Exporter
{
    protected static ?string $model = PurchaseOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_date')->label('Tanggal PO'),
            ExportColumn::make('number')->label('Nomor PO'),
            ExportColumn::make('purchaseRequest.number')->label('Nomor PR'),
            ExportColumn::make('kitchen.name')->label('SPPG'),
            ExportColumn::make('supplier.display_name')
                ->label('Supplier')
                ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-')),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
            ExportColumn::make('pr_approval_hours')
                ->label('PR Approval (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->prApprovalHours($record)),
            ExportColumn::make('po_generation_hours')
                ->label('PR ke PO (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->poGenerationHours($record)),
            ExportColumn::make('po_approval_hours')
                ->label('PO Approval (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->poApprovalHours($record)),
            ExportColumn::make('issue_hours')
                ->label('Approval ke Issue (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->issueHours($record)),
            ExportColumn::make('acknowledgement_hours')
                ->label('Supplier Ack (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->acknowledgementHours($record)),
            ExportColumn::make('first_receipt_hours')
                ->label('Ack ke First Receipt (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->firstReceiptHours($record)),
            ExportColumn::make('average_qc_hours')
                ->label('Rata-rata QC (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->averageQcHours($record)),
            ExportColumn::make('delivery_qc_cycle_hours')
                ->label('Delivery/QC Cycle (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->deliveryQcCycleHours($record)),
            ExportColumn::make('invoice_approval_hours')
                ->label('Invoice Approval (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->invoiceApprovalHours($record)),
            ExportColumn::make('payment_cycle_hours')
                ->label('Payment Cycle (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->paymentCycleHours($record)),
            ExportColumn::make('end_to_end_hours')
                ->label('End-to-End (jam)')
                ->state(fn (PurchaseOrder $record): ?float => self::metrics()->endToEndHours($record)),
            ExportColumn::make('longest_stage')
                ->label('Tahap Terlama')
                ->state(fn (PurchaseOrder $record): string => self::metrics()->longestCompletedStage($record)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Export process performance selesai. '.number_format($export->successful_rows).' baris berhasil diekspor.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' baris gagal diekspor.';
        }

        return $body;
    }

    private static function metrics(): ProcessPerformanceAnalyticsService
    {
        return app(ProcessPerformanceAnalyticsService::class);
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
