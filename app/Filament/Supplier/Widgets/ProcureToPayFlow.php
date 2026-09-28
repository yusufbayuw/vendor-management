<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\BusinessFlowStage;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Supplier\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource;
use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\DeliverySchedule;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Services\Supplier\SupplierPortalAccessService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

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

        $po = PurchaseOrder::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereNotIn('status', [PurchaseOrderStatus::Closed->value, PurchaseOrderStatus::Cancelled->value])
            ->count();

        $delivery = DeliverySchedule::query()
            ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query->whereIn('supplier_id', $supplierIds))
            ->whereIn('status', [
                DeliveryScheduleStatus::Draft->value,
                DeliveryScheduleStatus::Planned->value,
                DeliveryScheduleStatus::Confirmed->value,
                DeliveryScheduleStatus::InTransit->value,
                DeliveryScheduleStatus::Arrived->value,
                DeliveryScheduleStatus::PartiallyReceived->value,
            ])->count();

        $receiving = GoodsReceipt::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('status', GoodsReceiptStatus::PendingInspection->value)
            ->count();

        $invoice = Invoice::query()
            ->whereIn('supplier_id', $supplierIds)
            ->whereNotIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Cancelled->value])
            ->count();

        $payment = Payment::query()
            ->whereHas('invoice', static fn (Builder $query): Builder => $query->whereIn('supplier_id', $supplierIds))
            ->whereIn('status', [PaymentStatus::Draft->value, PaymentStatus::Submitted->value, PaymentStatus::UnderReview->value])
            ->count();

        return [
            $this->context(BusinessFlowStage::PurchaseRequest, 'SPPG', 'Kebutuhan dibuat oleh SPPG', 'heroicon-o-clipboard-document-list'),
            $this->actionable(BusinessFlowStage::PurchaseOrder, $po, PurchaseOrderResource::getUrl('index'), 'heroicon-o-document-text'),
            $this->actionable(BusinessFlowStage::Delivery, $delivery, DeliveryScheduleResource::getUrl('index'), 'heroicon-o-truck'),
            $this->context(BusinessFlowStage::Receiving, (string) $receiving, 'Diterima dan QC oleh SPPG', 'heroicon-o-check-circle'),
            $this->actionable(BusinessFlowStage::Invoice, $invoice, InvoiceResource::getUrl('index'), 'heroicon-o-document-currency-dollar'),
            $this->context(BusinessFlowStage::Payment, (string) $payment, 'Diproses dan diverifikasi pembeli', 'heroicon-o-banknotes'),
        ];
    }

    private function actionable(BusinessFlowStage $stage, int $count, string $url, string $icon): Stat
    {
        return Stat::make($stage->navigationLabel(), number_format($count, 0, ',', '.'))
            ->description($stage->description())
            ->icon($icon)
            ->url($url);
    }

    private function context(BusinessFlowStage $stage, string $value, string $description, string $icon): Stat
    {
        return Stat::make($stage->navigationLabel(), $value)
            ->description($description)
            ->icon($icon);
    }
}
