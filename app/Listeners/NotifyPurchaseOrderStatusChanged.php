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
            $supplierRecipients = $order->supplier->users()
                ->wherePivot('is_active', true)
                ->exists();

            if ($supplierRecipients) {
                $notifications->toSupplier(
                    $order->supplier,
                    'Purchase Order baru diterbitkan',
                    "{$order->number} telah diterbitkan dan menunggu konfirmasi supplier.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'warning',
                    '/supplier/purchase-orders',
                );
            } else {
                $notifications->toKitchenPermission(
                    $order->kitchen,
                    SystemPermission::PurchaseOrderAcknowledge,
                    'PO menunggu konfirmasi internal',
                    "{$order->number} diterbitkan untuk supplier tanpa akun portal. Konfirmasikan secara internal setelah persetujuan supplier diterima di luar sistem.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'warning',
                    '/admin/purchase-orders',
                );
            }
        }

        if ($status === PurchaseOrderStatus::Acknowledged) {
            $respondedBy = $order->responses()->latest('id')->value('responded_by');
            $confirmedBySupplier = $respondedBy !== null
                && $order->supplier->users()
                    ->wherePivot('is_active', true)
                    ->where('users.id', $respondedBy)
                    ->exists();

            $notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::DeliverySchedule,
                $confirmedBySupplier ? 'PO dikonfirmasi supplier' : 'PO dikonfirmasi internal',
                $confirmedBySupplier
                    ? "{$order->number} sudah dikonfirmasi supplier dan siap dijadwalkan."
                    : "{$order->number} dikonfirmasi admin atas nama supplier dan siap dijadwalkan.",
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
            $supplierRecipients = $order->supplier->users()
                ->wherePivot('is_active', true)
                ->exists();

            if ($supplierRecipients) {
                $notifications->toSupplier(
                    $order->supplier,
                    'PO siap ditagihkan',
                    "{$order->number} sudah direkonsiliasi dan dapat dibuatkan invoice.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'success',
                    '/supplier/invoices',
                );
            } else {
                $notifications->toKitchenPermission(
                    $order->kitchen,
                    SystemPermission::InvoiceReview,
                    'PO siap dibuatkan invoice internal',
                    "{$order->number} sudah direkonsiliasi. Supplier tidak memiliki akun portal; admin dapat membuat draft invoice dari PO.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'success',
                    '/admin/purchase-orders',
                );
            }
        }
    }
}
