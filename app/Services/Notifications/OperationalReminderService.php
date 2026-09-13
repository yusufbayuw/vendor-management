<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SupplierDocument;
use Illuminate\Support\Facades\Cache;

class OperationalReminderService
{
    public function __construct(private readonly NotificationDispatchService $notifications) {}

    /** @return array<string, int> */
    public function send(): array
    {
        return [
            'deliveries_tomorrow' => $this->deliveryTomorrow(),
            'overdue_deliveries' => $this->overdueDeliveries(),
            'unacknowledged_pos' => $this->unacknowledgedPurchaseOrders(),
            'expiring_documents' => $this->expiringSupplierDocuments(),
            'due_invoices' => $this->dueInvoices(),
        ];
    }

    private function deliveryTomorrow(): int
    {
        $count = 0;
        $start = now()->addDay()->startOfDay();
        $end = now()->addDay()->endOfDay();

        DeliverySchedule::query()
            ->with('purchaseOrder.kitchen', 'purchaseOrder.supplier')
            ->whereBetween('planned_delivery_at', [$start, $end])
            ->whereNotIn('status', [
                DeliveryScheduleStatus::Received->value,
                DeliveryScheduleStatus::Cancelled->value,
                DeliveryScheduleStatus::Missed->value,
            ])
            ->each(function (DeliverySchedule $schedule) use (&$count): void {
                if (! $this->oncePerDay('delivery-tomorrow', $schedule->getKey())) {
                    return;
                }

                $order = $schedule->purchaseOrder;
                $count += $this->notifications->toKitchenPermission(
                    $order->kitchen,
                    SystemPermission::GoodsReceiptCreate,
                    'Pengiriman dijadwalkan besok',
                    "{$schedule->number} untuk {$order->number} dijadwalkan besok.",
                    DeliverySchedule::class,
                    $schedule->getKey(),
                    'info',
                    '/admin/delivery-schedules',
                );
                $count += $this->notifications->toSupplier(
                    $order->supplier,
                    'Pengiriman dijadwalkan besok',
                    "{$schedule->number} untuk {$order->number} dijadwalkan besok.",
                    DeliverySchedule::class,
                    $schedule->getKey(),
                    'info',
                    '/supplier/delivery-schedules',
                );
            });

        return $count;
    }

    private function overdueDeliveries(): int
    {
        $count = 0;

        DeliverySchedule::query()
            ->with('purchaseOrder.kitchen', 'purchaseOrder.supplier')
            ->where('planned_delivery_at', '<', now())
            ->whereNotIn('status', [
                DeliveryScheduleStatus::Received->value,
                DeliveryScheduleStatus::Cancelled->value,
                DeliveryScheduleStatus::Missed->value,
            ])
            ->each(function (DeliverySchedule $schedule) use (&$count): void {
                if (! $this->oncePerDay('delivery-overdue', $schedule->getKey())) {
                    return;
                }

                $order = $schedule->purchaseOrder;
                $count += $this->notifications->toKitchenPermission(
                    $order->kitchen,
                    SystemPermission::DeliverySchedule,
                    'Pengiriman melewati jadwal',
                    "{$schedule->number} untuk {$order->number} sudah melewati waktu pengiriman.",
                    DeliverySchedule::class,
                    $schedule->getKey(),
                    'danger',
                    '/admin/delivery-schedules',
                );
                $count += $this->notifications->toSupplier(
                    $order->supplier,
                    'Pengiriman melewati jadwal',
                    "{$schedule->number} sudah melewati waktu pengiriman yang direncanakan.",
                    DeliverySchedule::class,
                    $schedule->getKey(),
                    'danger',
                    '/supplier/delivery-schedules',
                );
            });

        return $count;
    }

    private function unacknowledgedPurchaseOrders(): int
    {
        $count = 0;

        PurchaseOrder::query()
            ->with(['supplier', 'kitchen'])
            ->where('status', PurchaseOrderStatus::Issued->value)
            ->where('issued_at', '<=', now()->subDay())
            ->each(function (PurchaseOrder $order) use (&$count): void {
                if (! $this->oncePerDay('po-unacknowledged', $order->getKey())) {
                    return;
                }

                $count += $this->notifications->toSupplier(
                    $order->supplier,
                    'PO belum dikonfirmasi',
                    "{$order->number} telah diterbitkan lebih dari 24 jam dan belum dikonfirmasi.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'warning',
                    '/supplier/purchase-orders',
                );
                $count += $this->notifications->toKitchenPermission(
                    $order->kitchen,
                    SystemPermission::PurchaseOrderIssue,
                    'PO belum dikonfirmasi supplier',
                    "{$order->number} belum dikonfirmasi supplier lebih dari 24 jam.",
                    PurchaseOrder::class,
                    $order->getKey(),
                    'warning',
                    '/admin/purchase-orders',
                );
            });

        return $count;
    }

    private function expiringSupplierDocuments(): int
    {
        $count = 0;

        SupplierDocument::query()
            ->with('supplier')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [today(), today()->addDays(30)])
            ->each(function (SupplierDocument $document) use (&$count): void {
                if (! $this->oncePerDay('supplier-document-expiring', $document->getKey())) {
                    return;
                }

                $days = today()->diffInDays($document->expires_at, false);
                $body = "Dokumen {$document->document_type} akan kedaluwarsa dalam {$days} hari.";

                $count += $this->notifications->toSupplier(
                    $document->supplier,
                    'Dokumen supplier akan kedaluwarsa',
                    $body,
                    SupplierDocument::class,
                    $document->getKey(),
                    'warning',
                    '/supplier/documents',
                );
                $count += $this->notifications->toPermission(
                    SystemPermission::SupplierVerify,
                    'Dokumen supplier akan kedaluwarsa',
                    $document->supplier->display_name.': '.$body,
                    SupplierDocument::class,
                    $document->getKey(),
                    'warning',
                    '/admin/supplier-documents',
                );
            });

        return $count;
    }

    private function dueInvoices(): int
    {
        $count = 0;

        Invoice::query()
            ->with(['kitchen', 'supplier'])
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::PartiallyPaid->value])
            ->whereNotNull('due_date')
            ->where('due_date', '<=', today()->addDays(3))
            ->each(function (Invoice $invoice) use (&$count): void {
                if (! $this->oncePerDay('invoice-due', $invoice->getKey())) {
                    return;
                }

                $overdue = $invoice->due_date->isPast();
                $body = $overdue
                    ? "{$invoice->number} telah melewati jatuh tempo."
                    : "{$invoice->number} akan jatuh tempo pada {$invoice->due_date->format('d/m/Y')}.";

                $count += $this->notifications->toKitchenPermission(
                    $invoice->kitchen,
                    SystemPermission::PaymentCreate,
                    $overdue ? 'Invoice melewati jatuh tempo' : 'Invoice mendekati jatuh tempo',
                    $body,
                    Invoice::class,
                    $invoice->getKey(),
                    $overdue ? 'danger' : 'warning',
                    '/admin/invoices',
                );
                $count += $this->notifications->toSupplier(
                    $invoice->supplier,
                    $overdue ? 'Invoice melewati jatuh tempo' : 'Invoice mendekati jatuh tempo',
                    $body,
                    Invoice::class,
                    $invoice->getKey(),
                    $overdue ? 'danger' : 'warning',
                    '/supplier/invoices',
                );
            });

        return $count;
    }

    private function oncePerDay(string $type, int $id): bool
    {
        return Cache::add(
            "vendor-management:reminder:{$type}:{$id}:".today()->toDateString(),
            true,
            now()->endOfDay(),
        );
    }
}
