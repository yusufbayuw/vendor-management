<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\BusinessFlowStage;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Supplier\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource;
use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Supplier\SupplierPortalAccessService;
use App\Services\Workflow\ProcureToPayLifecycleService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProcureToPayFlow extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && app(SupplierPortalAccessService::class)->hasActiveSupplier($user);
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $supplierIds = app(SupplierPortalAccessService::class)->activeSupplierIds($user);
        $lifecycle = app(ProcureToPayLifecycleService::class);

        $counts = collect(BusinessFlowStage::cases())
            ->mapWithKeys(static fn (BusinessFlowStage $stage): array => [$stage->value => 0])
            ->all();

        $orders = PurchaseOrder::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereNotIn('status', [
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->with(['purchaseRequest', 'deliverySchedules', 'goodsReceipts', 'invoice.payments'])
            ->get();

        foreach ($orders as $order) {
            $counts[$lifecycle->currentStage($order)->value]++;
        }

        return [
            $this->context(BusinessFlowStage::PurchaseRequest, 'SPPG', 'Kebutuhan dibuat oleh SPPG', 'heroicon-o-clipboard-document-list'),
            $this->actionable(BusinessFlowStage::PurchaseOrder, $counts['po'], PurchaseOrderResource::getUrl('index'), 'heroicon-o-document-text'),
            $this->actionable(BusinessFlowStage::Delivery, $counts['delivery'], DeliveryScheduleResource::getUrl('index'), 'heroicon-o-truck'),
            $this->context(BusinessFlowStage::Receiving, (string) $counts['receiving'], 'WIP · diterima dan QC oleh SPPG', 'heroicon-o-check-circle'),
            $this->actionable(BusinessFlowStage::Invoice, $counts['invoice'], InvoiceResource::getUrl('index'), 'heroicon-o-document-text'),
            $this->context(BusinessFlowStage::Payment, (string) $counts['payment'], 'WIP · diproses pembeli', 'heroicon-o-banknotes'),
        ];
    }

    private function actionable(BusinessFlowStage $stage, int $count, string $url, string $icon): Stat
    {
        return Stat::make($stage->navigationLabel(), number_format($count, 0, ',', '.'))
            ->description('WIP · '.$stage->description())
            ->icon($icon)
            ->color($count > 0 ? 'warning' : 'success')
            ->url($url);
    }

    private function context(BusinessFlowStage $stage, string $value, string $description, string $icon): Stat
    {
        return Stat::make($stage->navigationLabel(), $value)
            ->description($description)
            ->icon($icon);
    }
}
