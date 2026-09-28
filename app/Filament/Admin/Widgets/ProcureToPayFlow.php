<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\BusinessFlowStage;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Admin\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Admin\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\DeliverySchedule;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\Access\UserAccessService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ProcureToPayFlow extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $access = app(UserAccessService::class);

        $pr = $access->applyKitchenOwnedScope(PurchaseRequest::query(), $user)
            ->whereIn('status', [
                PurchaseRequestStatus::Draft->value,
                PurchaseRequestStatus::Submitted->value,
                PurchaseRequestStatus::UnderReview->value,
                PurchaseRequestStatus::Approved->value,
                PurchaseRequestStatus::PartiallyAllocated->value,
                PurchaseRequestStatus::FullyAllocated->value,
            ])->count();

        $po = $access->applyKitchenOwnedScope(PurchaseOrder::query(), $user)
            ->whereIn('status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::PendingApproval->value,
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Issued->value,
                PurchaseOrderStatus::Acknowledged->value,
            ])->count();

        $delivery = DeliverySchedule::query()
            ->whereHas('purchaseOrder', function (Builder $query) use ($access, $user): void {
                $access->applyKitchenOwnedScope($query, $user);
            })
            ->whereIn('status', [
                DeliveryScheduleStatus::Draft->value,
                DeliveryScheduleStatus::Planned->value,
                DeliveryScheduleStatus::Confirmed->value,
                DeliveryScheduleStatus::InTransit->value,
                DeliveryScheduleStatus::Arrived->value,
                DeliveryScheduleStatus::PartiallyReceived->value,
            ])->count();

        $receiving = $access->applyKitchenOwnedScope(GoodsReceipt::query(), $user)
            ->where('status', GoodsReceiptStatus::PendingInspection->value)
            ->count();

        $invoice = $access->applyKitchenOwnedScope(Invoice::query(), $user)
            ->whereIn('status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Submitted->value,
                InvoiceStatus::UnderReview->value,
                InvoiceStatus::Approved->value,
                InvoiceStatus::PartiallyPaid->value,
            ])->count();

        $payment = Payment::query()
            ->whereHas('invoice', function (Builder $query) use ($access, $user): void {
                $access->applyKitchenOwnedScope($query, $user);
            })
            ->whereIn('status', [
                PaymentStatus::Draft->value,
                PaymentStatus::Submitted->value,
                PaymentStatus::UnderReview->value,
            ])->count();

        return [
            $this->stage(BusinessFlowStage::PurchaseRequest, $pr, PurchaseRequestResource::canViewAny() ? PurchaseRequestResource::getUrl('index') : null, 'heroicon-o-clipboard-document-list'),
            $this->stage(BusinessFlowStage::PurchaseOrder, $po, PurchaseOrderResource::canViewAny() ? PurchaseOrderResource::getUrl('index') : null, 'heroicon-o-document-text'),
            $this->stage(BusinessFlowStage::Delivery, $delivery, DeliveryScheduleResource::canViewAny() ? DeliveryScheduleResource::getUrl('index') : null, 'heroicon-o-truck'),
            $this->stage(BusinessFlowStage::Receiving, $receiving, GoodsReceiptResource::canViewAny() ? GoodsReceiptResource::getUrl('index') : null, 'heroicon-o-check-circle'),
            $this->stage(BusinessFlowStage::Invoice, $invoice, InvoiceResource::canViewAny() ? InvoiceResource::getUrl('index') : null, 'heroicon-o-document-currency-dollar'),
            $this->stage(BusinessFlowStage::Payment, $payment, PaymentResource::canViewAny() ? PaymentResource::getUrl('index') : null, 'heroicon-o-banknotes'),
        ];
    }

    private function stage(BusinessFlowStage $stage, int $count, ?string $url, string $icon): Stat
    {
        $stat = Stat::make($stage->navigationLabel(), number_format($count, 0, ',', '.'))
            ->description($stage->description())
            ->icon($icon);

        return $url === null ? $stat : $stat->url($url);
    }
}
