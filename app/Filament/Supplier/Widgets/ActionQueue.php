<?php

namespace App\Filament\Supplier\Widgets;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource;
use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Services\Supplier\SupplierPortalAccessService;
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

        return $user !== null
            && app(SupplierPortalAccessService::class)->hasActiveSupplier($user)
            && collect([
                SystemPermission::PurchaseOrderAcknowledge,
                SystemPermission::DeliveryManage,
                SystemPermission::InvoiceSubmit,
            ])->contains(static fn (SystemPermission $permission): bool => $user->can($permission->value));
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $supplierIds = app(SupplierPortalAccessService::class)->activeSupplierIds($user);
        $stats = [];
        $can = static fn (SystemPermission $permission): bool => $user->can($permission->value);
        $purchaseOrders = static fn (): Builder => PurchaseOrder::query()->whereIn('supplier_id', $supplierIds);
        $deliverySchedules = static fn (): Builder => DeliverySchedule::query()
            ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query->whereIn('supplier_id', $supplierIds));
        $invoices = static fn (): Builder => Invoice::query()->whereIn('supplier_id', $supplierIds);

        $this->pushTask(
            $stats,
            $can(SystemPermission::PurchaseOrderAcknowledge),
            $purchaseOrders()->where('status', PurchaseOrderStatus::Issued->value)->count(),
            'PO Perlu Konfirmasi',
            'Konfirmasi Purchase Order baru',
            'heroicon-o-inbox-arrow-down',
            PurchaseOrderResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::DeliveryManage),
            $purchaseOrders()->whereIn('status', [PurchaseOrderStatus::Acknowledged->value, PurchaseOrderStatus::PartiallyDelivered->value])->count(),
            'Jadwal Pengiriman',
            'Buat atau lanjutkan jadwal pengiriman',
            'heroicon-o-calendar-days',
            PurchaseOrderResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::DeliveryManage),
            $deliverySchedules()->whereIn('status', [DeliveryScheduleStatus::Draft->value, DeliveryScheduleStatus::Planned->value])->count(),
            'Konfirmasi Jadwal',
            'Konfirmasi jadwal yang sudah dibuat',
            'heroicon-o-calendar-days',
            DeliveryScheduleResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::DeliveryManage),
            $deliverySchedules()->where('status', DeliveryScheduleStatus::Confirmed->value)->count(),
            'Siap Berangkat',
            'Isi kendaraan dan tandai pengiriman berangkat',
            'heroicon-o-truck',
            DeliveryScheduleResource::getUrl('index'),
        );

        $this->pushTask(
            $stats,
            $can(SystemPermission::InvoiceSubmit),
            $invoices()->where('status', InvoiceStatus::Draft->value)->count(),
            'Invoice Draft',
            'Upload file invoice lalu ajukan',
            'heroicon-o-document-arrow-up',
            InvoiceResource::getUrl('index'),
        );

        if ($stats === []) {
            return [
                Stat::make('Tugas Saya', '0')
                    ->description('Tidak ada tindakan tertunda untuk supplier Anda')
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
}
