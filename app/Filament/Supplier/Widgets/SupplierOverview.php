<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $supplierIds = $user->suppliers()
            ->wherePivot('is_active', true)
            ->pluck('suppliers.id');

        $waitingAcknowledgement = PurchaseOrder::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('status', PurchaseOrderStatus::Issued->value)
            ->count();

        $activeOrders = PurchaseOrder::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereNotIn('status', [
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->count();

        $upcomingDeliveries = DeliverySchedule::query()
            ->whereHas('purchaseOrder', fn ($query) => $query->whereIn('supplier_id', $supplierIds))
            ->where('planned_delivery_at', '>=', now())
            ->whereNotIn('status', [
                DeliveryScheduleStatus::Received->value,
                DeliveryScheduleStatus::Cancelled->value,
                DeliveryScheduleStatus::Missed->value,
            ])
            ->count();

        $outstanding = Invoice::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::PartiallyPaid->value])
            ->withSum([
                'payments as verified_paid_amount' => fn ($query) => $query->where('status', PaymentStatus::Verified->value),
            ], 'amount')
            ->get()
            ->sum(fn (Invoice $invoice): float => max(0, (float) $invoice->payable_amount - (float) ($invoice->verified_paid_amount ?? 0)));

        return [
            Stat::make('PO Menunggu Konfirmasi', number_format($waitingAcknowledgement, 0, ',', '.'))
                ->description('PO baru yang perlu direspons')
                ->color($waitingAcknowledgement > 0 ? 'warning' : 'success'),
            Stat::make('PO Aktif', number_format($activeOrders, 0, ',', '.'))
                ->description('Seluruh PO yang belum ditutup')
                ->color('primary'),
            Stat::make('Pengiriman Mendatang', number_format($upcomingDeliveries, 0, ',', '.'))
                ->description('Jadwal yang belum selesai')
                ->color('info'),
            Stat::make('Piutang Outstanding', 'Rp '.number_format($outstanding, 0, ',', '.'))
                ->description('Invoice approved setelah pembayaran terverifikasi')
                ->color($outstanding > 0 ? 'warning' : 'success'),
        ];
    }
}
