<?php

namespace App\Listeners;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\SystemPermission;
use App\Events\DeliveryScheduleStatusChanged;
use App\Models\DeliverySchedule;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyDeliveryScheduleStatusChanged implements ShouldQueue
{
    public function handle(DeliveryScheduleStatusChanged $event): void
    {
        $status = DeliveryScheduleStatus::tryFrom($event->status);
        $schedule = DeliverySchedule::query()
            ->with(['purchaseOrder.kitchen', 'purchaseOrder.supplier'])
            ->find($event->deliveryScheduleId);

        if ($status === null || $schedule === null || $schedule->status !== $status) {
            return;
        }

        $order = $schedule->purchaseOrder;
        $notifications = app(NotificationDispatchService::class);

        if ($status === DeliveryScheduleStatus::Confirmed) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::GoodsReceiptCreate,
                'Jadwal pengiriman dikonfirmasi',
                "{$schedule->number} untuk {$order->number} telah dikonfirmasi supplier.",
                DeliverySchedule::class,
                $schedule->getKey(),
                'info',
                '/admin/delivery-schedules',
            );
        }

        if ($status === DeliveryScheduleStatus::InTransit) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::GoodsReceiptCreate,
                'Pengiriman sedang menuju SPPG',
                "{$schedule->number} untuk {$order->number} sedang dalam perjalanan.",
                DeliverySchedule::class,
                $schedule->getKey(),
                'warning',
                '/admin/delivery-schedules',
            );
        }

        if ($status === DeliveryScheduleStatus::Arrived) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::GoodsReceiptCreate,
                'Pengiriman tiba',
                "{$schedule->number} telah tiba dan siap diproses pada tahap Receiving.",
                DeliverySchedule::class,
                $schedule->getKey(),
                'warning',
                '/admin/delivery-schedules',
            );
        }

        if ($status === DeliveryScheduleStatus::Missed) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::DeliverySchedule,
                'Pengiriman terlewat',
                "{$schedule->number} melewati jadwal dan memerlukan tindak lanjut.",
                DeliverySchedule::class,
                $schedule->getKey(),
                'danger',
                '/admin/delivery-schedules',
            );

            $notifications->toSupplier(
                $order->supplier,
                'Pengiriman terlewat',
                "{$schedule->number} melewati jadwal pengiriman.",
                DeliverySchedule::class,
                $schedule->getKey(),
                'danger',
                '/supplier/delivery-schedules',
            );
        }
    }
}
