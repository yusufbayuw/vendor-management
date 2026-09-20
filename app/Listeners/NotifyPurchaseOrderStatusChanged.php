<?php

namespace App\Listeners;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Events\PurchaseOrderStatusChanged;
use App\Models\PurchaseOrder;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPurchaseOrderStatusChanged implements ShouldQueue
{
    public function handle(PurchaseOrderStatusChanged $event): void
    {
        $status = PurchaseOrderStatus::tryFrom($event->status);
        $order = PurchaseOrder::query()
            ->with(['kitchen', 'supplier'])
            ->find($event->purchaseOrderId);

        if ($status === null || $order === null || $order->status !== $status) {
            return;
        }

        $notifications = app(NotificationDispatchService::class);

        if ($status === PurchaseOrderStatus::Issued) {
            $notifications->toSupplier(
                $order->supplier,
                'Purchase Order baru diterbitkan',
                "{$order->number} telah diterbitkan dan menunggu konfirmasi supplier.",
                PurchaseOrder::class,
                $order->getKey(),
                'warning',
                '/supplier/purchase-orders',
            );
        }

        if ($status === PurchaseOrderStatus::Acknowledged) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::DeliverySchedule,
                'PO dikonfirmasi supplier',
                "{$order->number} sudah dikonfirmasi supplier dan siap dijadwalkan.",
                PurchaseOrder::class,
                $order->getKey(),
                'success',
                '/admin/purchase-orders',
            );
        }

        if ($status === PurchaseOrderStatus::PendingExceptionClosure) {
            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::PurchaseOrderExceptionClose,
                'PO menunggu penutupan exception',
                "{$order->number} memiliki selisih fulfillment yang menunggu keputusan.",
                PurchaseOrder::class,
                $order->getKey(),
                'warning',
                '/admin/purchase-orders',
            );
        }

        if (in_array($status, [
            PurchaseOrderStatus::Fulfilled,
            PurchaseOrderStatus::ClosedWithException,
        ], true)) {
            $notifications->toSupplier(
                $order->supplier,
                'PO siap ditagihkan',
                "{$order->number} sudah direkonsiliasi dan dapat dibuatkan invoice.",
                PurchaseOrder::class,
                $order->getKey(),
                'success',
                '/supplier/invoices',
            );
        }
    }
}
