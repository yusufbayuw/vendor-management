<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\ProcurementReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ProcurementReportOverview extends StatsOverviewWidget
{
    public ?string $fromDate = null;

    public ?string $toDate = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return 'Ringkasan Procurement';
    }

    protected function getDescription(): ?string
    {
        [$fromDate, $toDate] = $this->dateRange();

        return sprintf(
            'Periode %s sampai %s',
            Carbon::parse($fromDate)->translatedFormat('d F Y'),
            Carbon::parse($toDate)->translatedFormat('d F Y'),
        );
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        [$fromDate, $toDate] = $this->dateRange();

        $summary = app(ProcurementReportService::class)->summary(
            $user,
            $fromDate,
            $toDate,
        );

        return [
            Stat::make('Jumlah PO', number_format($summary['po_count'], 0, ',', '.'))
                ->description('Purchase order pada periode terpilih')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),
            Stat::make('Nilai PO', 'Rp '.number_format($summary['po_value'], 0, ',', '.'))
                ->description('Total nilai purchase order')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
            Stat::make('Supplier Aktif pada PO', number_format($summary['supplier_count'], 0, ',', '.'))
                ->description('Supplier unik pada purchase order')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),
            Stat::make('Discrepancy Terbuka', number_format($summary['open_discrepancy_count'], 0, ',', '.'))
                ->description('Masih memerlukan rekonsiliasi')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($summary['open_discrepancy_count'] > 0 ? 'warning' : 'success'),
            Stat::make('Jumlah Invoice', number_format($summary['invoice_count'], 0, ',', '.'))
                ->description('Invoice pada periode terpilih')
                ->descriptionIcon('heroicon-m-document-currency-dollar')
                ->color('info'),
            Stat::make('Nilai Invoice', 'Rp '.number_format($summary['invoice_value'], 0, ',', '.'))
                ->description('Total payable invoice')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),
            Stat::make('Pembayaran Terverifikasi', 'Rp '.number_format($summary['verified_payment_value'], 0, ',', '.'))
                ->description('Total pembayaran yang sudah diverifikasi')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Outstanding', 'Rp '.number_format($summary['outstanding_value'], 0, ',', '.'))
                ->description('Sisa payable setelah pembayaran terverifikasi')
                ->descriptionIcon('heroicon-m-clock')
                ->color($summary['outstanding_value'] > 0 ? 'warning' : 'success'),
        ];
    }

    /** @return array{string, string} */
    private function dateRange(): array
    {
        return [
            $this->fromDate ?: now()->startOfMonth()->toDateString(),
            $this->toDate ?: now()->toDateString(),
        ];
    }
}
