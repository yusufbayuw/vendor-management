<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
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

class ActionQueue extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return collect(static::actionPermissions())
            ->contains(static fn (SystemPermission $permission): bool => $user->can($permission->value));
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $access = app(UserAccessService::class);
        $stats = [];
        $can = static fn (SystemPermission $permission): bool => $user->can($permission->value);
        $purchaseRequests = static fn (): Builder => $access->applyKitchenOwnedScope(PurchaseRequest::query(), $user);
        $purchaseOrders = static fn (): Builder => $access->applyKitchenOwnedScope(PurchaseOrder::query(), $user);
        $invoices = static fn (): Builder => $access->applyKitchenOwnedScope(Invoice::query(), $user);
        $deliverySchedules = static fn (): Builder => DeliverySchedule::query()
            ->whereHas('purchaseOrder', function (Builder $query) use ($access, $user): void {
                $access->applyKitchenOwnedScope($query, $user);
            });
        $payments = static fn (): Builder => Payment::query()
            ->whereHas('invoice', function (Builder $query) use ($access, $user): void {
                $access->applyKitchenOwnedScope($query, $user);
            });

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseRequestSubmit),
            $purchaseRequests()->where('status', PurchaseRequestStatus::Draft->value)->where('requested_by', $user->getKey())->count(),
            'PR Draft Saya',
            'Lengkapi dan ajukan PR',
            'heroicon-o-pencil-square',
            PurchaseRequestResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseRequestApprove),
            $purchaseRequests()->whereIn('status', [PurchaseRequestStatus::Submitted->value, PurchaseRequestStatus::UnderReview->value])->count(),
            'Approval PR',
            'Periksa lalu setujui atau tolak',
            'heroicon-o-clipboard-document-check',
            PurchaseRequestResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseRequestAllocate),
            $purchaseRequests()->whereIn('status', [PurchaseRequestStatus::Approved->value, PurchaseRequestStatus::PartiallyAllocated->value])->count(),
            'Alokasi Supplier',
            'Mapping item PR ke supplier',
            'heroicon-o-arrows-right-left',
            PurchaseRequestResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseOrderCreate),
            $purchaseRequests()->where('status', PurchaseRequestStatus::FullyAllocated->value)->count(),
            'Generate PO',
            'PR sudah siap dibuatkan Purchase Order',
            'heroicon-o-document-plus',
            PurchaseRequestResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseOrderApprove),
            $purchaseOrders()->where('status', PurchaseOrderStatus::PendingApproval->value)->count(),
            'Approval PO',
            'Periksa dan putuskan Purchase Order',
            'heroicon-o-document-check',
            PurchaseOrderResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseOrderIssue),
            $purchaseOrders()->where('status', PurchaseOrderStatus::Approved->value)->count(),
            'PO Siap Terbit',
            'Kirim Purchase Order ke supplier',
            'heroicon-o-paper-airplane',
            PurchaseOrderResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::DeliverySchedule),
            $deliverySchedules()->whereIn('status', [DeliveryScheduleStatus::Draft->value, DeliveryScheduleStatus::Planned->value])->count(),
            'Konfirmasi Jadwal',
            'Konfirmasi jadwal pengiriman supplier',
            'heroicon-o-calendar-days',
            DeliveryScheduleResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::GoodsReceiptCreate),
            $deliverySchedules()
                ->whereIn('status', [
                    DeliveryScheduleStatus::Confirmed->value,
                    DeliveryScheduleStatus::InTransit->value,
                    DeliveryScheduleStatus::Arrived->value,
                    DeliveryScheduleStatus::PartiallyReceived->value,
                ])
                ->whereDate('planned_delivery_at', '<=', today())
                ->count(),
            'Penerimaan Jatuh Tempo',
            'Catat pengiriman hari ini atau yang sudah melewati jadwal',
            'heroicon-o-arrow-down-tray',
            DeliveryScheduleResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::GoodsReceiptInspect),
            $access->applyKitchenOwnedScope(GoodsReceipt::query(), $user)
                ->where('status', GoodsReceiptStatus::PendingInspection->value)
                ->count(),
            'QC Menunggu',
            'Periksa dan selesaikan QC penerimaan',
            'heroicon-o-magnifying-glass-circle',
            GoodsReceiptResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseOrderExceptionClose),
            $purchaseOrders()->whereIn('status', [PurchaseOrderStatus::PartiallyDelivered->value, PurchaseOrderStatus::PendingExceptionClosure->value])->count(),
            'Exception PO',
            'Selesaikan kekurangan atau close with exception',
            'heroicon-o-exclamation-triangle',
            PurchaseOrderResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::InvoiceReview),
            $invoices()->whereIn('status', [InvoiceStatus::Submitted->value, InvoiceStatus::UnderReview->value])->count(),
            'Review Invoice',
            'Periksa invoice dan adjustment bila diperlukan',
            'heroicon-o-document-magnifying-glass',
            InvoiceResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::InvoiceApprove),
            $invoices()->whereIn('status', [InvoiceStatus::Submitted->value, InvoiceStatus::UnderReview->value])->count(),
            'Approval Invoice',
            'Setujui atau tahan invoice',
            'heroicon-o-check-badge',
            InvoiceResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PaymentCreate),
            $invoices()->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::PartiallyPaid->value])->count(),
            'Pembayaran',
            'Buat pembayaran untuk invoice approved',
            'heroicon-o-banknotes',
            InvoiceResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::PaymentVerify),
            $payments()->whereIn('status', [PaymentStatus::Submitted->value, PaymentStatus::UnderReview->value])->count(),
            'Verifikasi Pembayaran',
            'Verifikasi atau tolak pembayaran',
            'heroicon-o-shield-check',
            PaymentResource::getUrl('index'),
        );

        if ($stats === []) {
            return [
                Stat::make('Tugas Saya', '0')
                    ->description('Tidak ada tindakan tertunda dalam scope Anda')
                    ->icon('heroicon-o-check-circle')
                    ->color('success'),
            ];
        }

        return $stats;
    }

    /** @param array<int, Stat> $stats */
    private function pushTask(
        array &$stats,
        bool $allowed,
        int $count,
        string $label,
        string $description,
        string $icon,
        string $url,
    ): void {
        if (! $allowed || $count < 1) {
            return;
        }

        $stats[] = Stat::make($label, number_format($count, 0, ',', '.'))
            ->description($description)
            ->icon($icon)
            ->color('warning')
            ->url($url);
    }

    /** @return array<int, SystemPermission> */
    private static function actionPermissions(): array
    {
        return [
            SystemPermission::PurchaseRequestSubmit,
            SystemPermission::PurchaseRequestApprove,
            SystemPermission::PurchaseRequestAllocate,
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderIssue,
            SystemPermission::DeliverySchedule,
            SystemPermission::GoodsReceiptCreate,
            SystemPermission::GoodsReceiptInspect,
            SystemPermission::PurchaseOrderExceptionClose,
            SystemPermission::InvoiceReview,
            SystemPermission::InvoiceApprove,
            SystemPermission::PaymentCreate,
            SystemPermission::PaymentVerify,
        ];
    }
}
