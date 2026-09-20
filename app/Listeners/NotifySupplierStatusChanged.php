<?php

namespace App\Listeners;

use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Events\SupplierStatusChanged;
use App\Models\Supplier;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifySupplierStatusChanged implements ShouldQueue
{
    public function handle(SupplierStatusChanged $event): void
    {
        $status = SupplierStatus::tryFrom($event->status);
        $supplier = Supplier::query()->find($event->supplierId);

        if ($status === null || $supplier === null) {
            return;
        }

        $notifications = app(NotificationDispatchService::class);

        if ($status === SupplierStatus::Submitted) {
            $notifications->toPermission(
                SystemPermission::SupplierVerify,
                'Supplier baru menunggu verifikasi',
                "{$supplier->display_name} telah mengajukan pendaftaran supplier.",
                Supplier::class,
                $supplier->getKey(),
                'warning',
                '/admin/suppliers',
            );

            return;
        }

        if (in_array($status, [
            SupplierStatus::Active,
            SupplierStatus::RevisionRequired,
            SupplierStatus::Rejected,
            SupplierStatus::Suspended,
        ], true)) {
            $notifications->toSupplier(
                $supplier,
                'Status supplier diperbarui',
                "Status {$supplier->display_name} sekarang: {$status->label()}.",
                Supplier::class,
                $supplier->getKey(),
                $status === SupplierStatus::Active ? 'success' : 'warning',
                '/supplier',
            );
        }
    }
}
