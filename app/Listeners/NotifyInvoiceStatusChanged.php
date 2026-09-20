<?php

namespace App\Listeners;

use App\Enums\InvoiceStatus;
use App\Enums\SystemPermission;
use App\Events\InvoiceStatusChanged;
use App\Models\Invoice;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyInvoiceStatusChanged implements ShouldQueue
{
    public function handle(InvoiceStatusChanged $event): void
    {
        $status = InvoiceStatus::tryFrom($event->status);
        $invoice = Invoice::query()->with('kitchen')->find($event->invoiceId);

        if ($status === null || $invoice === null) {
            return;
        }

        $notifications = app(NotificationDispatchService::class);

        if ($status === InvoiceStatus::Submitted) {
            $notifications->toKitchenPermission(
                $invoice->kitchen,
                SystemPermission::InvoiceApprove,
                'Invoice menunggu approval',
                "{$invoice->number} telah diajukan supplier/finance.",
                Invoice::class,
                $invoice->getKey(),
                'warning',
                '/admin/invoices',
            );
        }

        if ($status === InvoiceStatus::Approved) {
            $notifications->toKitchenPermission(
                $invoice->kitchen,
                SystemPermission::PaymentCreate,
                'Invoice siap dibayar',
                "{$invoice->number} telah disetujui dan siap diproses pembayarannya.",
                Invoice::class,
                $invoice->getKey(),
                'success',
                '/admin/payments',
            );
        }
    }
}
