<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\BusinessFlowStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Admin\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Admin\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\Access\UserAccessService;
use App\Services\Workflow\ProcureToPayLifecycleService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
        $lifecycle = app(ProcureToPayLifecycleService::class);

        $counts = collect(BusinessFlowStage::cases())
            ->mapWithKeys(static fn (BusinessFlowStage $stage): array => [$stage->value => 0])
            ->all();

        $openRequestsWithoutPo = $access->applyKitchenOwnedScope(PurchaseRequest::query(), $user)
            ->whereDoesntHave('purchaseOrders')
            ->whereNotIn('status', [
                PurchaseRequestStatus::Closed->value,
                PurchaseRequestStatus::Cancelled->value,
                PurchaseRequestStatus::Rejected->value,
            ])
            ->count();

        $counts[BusinessFlowStage::PurchaseRequest->value] += $openRequestsWithoutPo;

        $orders = $access->applyKitchenOwnedScope(PurchaseOrder::query(), $user)
            ->with(['purchaseRequest', 'deliverySchedules', 'goodsReceipts', 'invoice.payments'])
            ->whereNotIn('status', [
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->get();

        foreach ($orders as $order) {
            $counts[$lifecycle->currentStage($order)->value]++;
        }

        return [
            $this->stage(BusinessFlowStage::PurchaseRequest, $counts['pr'], PurchaseRequestResource::canViewAny() ? PurchaseRequestResource::getUrl('index') : null, 'heroicon-o-clipboard-document-list'),
            $this->stage(BusinessFlowStage::PurchaseOrder, $counts['po'], PurchaseOrderResource::canViewAny() ? PurchaseOrderResource::getUrl('index') : null, 'heroicon-o-document-text'),
            $this->stage(BusinessFlowStage::Delivery, $counts['delivery'], DeliveryScheduleResource::canViewAny() ? DeliveryScheduleResource::getUrl('index') : null, 'heroicon-o-truck'),
            $this->stage(BusinessFlowStage::Receiving, $counts['receiving'], GoodsReceiptResource::canViewAny() ? GoodsReceiptResource::getUrl('index') : null, 'heroicon-o-check-circle'),
            $this->stage(BusinessFlowStage::Invoice, $counts['invoice'], InvoiceResource::canViewAny() ? InvoiceResource::getUrl('index') : null, 'heroicon-o-document-text'),
            $this->stage(BusinessFlowStage::Payment, $counts['payment'], PaymentResource::canViewAny() ? PaymentResource::getUrl('index') : null, 'heroicon-o-banknotes'),
        ];
    }

    private function stage(BusinessFlowStage $stage, int $count, ?string $url, string $icon): Stat
    {
        $stat = Stat::make($stage->navigationLabel(), number_format($count, 0, ',', '.'))
            ->description('WIP · '.$stage->description())
            ->icon($icon)
            ->color($count > 0 ? 'warning' : 'success');

        return $url === null ? $stat : $stat->url($url);
    }
}
