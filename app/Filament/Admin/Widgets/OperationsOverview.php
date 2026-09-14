<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\DiscrepancyStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Admin\Pages\DiscrepancyAnalytics;
use App\Filament\Admin\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\DeliverySchedule;
use App\Models\FulfillmentDiscrepancy;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Services\Access\UserAccessService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $access = app(UserAccessService::class);
        $kitchenIds = $access->applyKitchenScope(SppgKitchen::query(), $user)->pluck('id');

        $pendingRequests = $access->applyKitchenOwnedScope(PurchaseRequest::query(), $user)
            ->whereIn('status', [
                PurchaseRequestStatus::Submitted->value,
                PurchaseRequestStatus::UnderReview->value,
            ])
            ->count();

        $activeOrders = $access->applyKitchenOwnedScope(PurchaseOrder::query(), $user)
            ->whereNotIn('status', [
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->count();

        $todayDeliveries = DeliverySchedule::query()
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereIn('sppg_kitchen_id', $kitchenIds))
            ->whereDate('planned_delivery_at', today())
            ->count();

        $openDiscrepancies = FulfillmentDiscrepancy::query()
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereIn('sppg_kitchen_id', $kitchenIds))
            ->where('status', DiscrepancyStatus::Open->value)
            ->count();

        $outstanding = Invoice::query()
            ->whereIn('sppg_kitchen_id', $kitchenIds)
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::PartiallyPaid->value])
            ->withSum([
                'payments as verified_paid_amount' => fn ($query) => $query->where('status', PaymentStatus::Verified->value),
            ], 'amount')
            ->get()
            ->sum(fn (Invoice $invoice): float => max(0, (float) $invoice->payable_amount - (float) ($invoice->verified_paid_amount ?? 0)));

        return [
            Stat::make('PR Menunggu Approval', number_format($pendingRequests, 0, ',', '.'))
                ->description('Purchase request yang perlu keputusan')
                ->icon('heroicon-o-clipboard-document-check')
                ->color($pendingRequests > 0 ? 'warning' : 'success')
                ->url(PurchaseRequestResource::getUrl('index')),
            Stat::make('PO Aktif', number_format($activeOrders, 0, ',', '.'))
                ->description('Belum closed/cancelled')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->url(PurchaseOrderResource::getUrl('index')),
            Stat::make('Pengiriman Hari Ini', number_format($todayDeliveries, 0, ',', '.'))
                ->description('Jadwal seluruh SPPG dalam scope')
                ->icon('heroicon-o-truck')
                ->color('info')
                ->url(DeliveryScheduleResource::getUrl('index')),
            Stat::make('Discrepancy Terbuka', number_format($openDiscrepancies, 0, ',', '.'))
                ->description('Perlu rekonsiliasi')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($openDiscrepancies > 0 ? 'danger' : 'success')
                ->url(DiscrepancyAnalytics::getUrl()),
            Stat::make('Outstanding Invoice', 'Rp '.number_format($outstanding, 0, ',', '.'))
                ->description('Setelah pembayaran terverifikasi')
                ->icon('heroicon-o-banknotes')
                ->color($outstanding > 0 ? 'warning' : 'success')
                ->url(InvoiceResource::getUrl('index')),
        ];
    }
}
