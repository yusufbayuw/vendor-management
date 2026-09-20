<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Enums\SystemPermission;
use App\Events\PaymentStatusChanged;
use App\Models\Payment;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPaymentStatusChanged implements ShouldQueue
{
    public function handle(PaymentStatusChanged $event): void
    {
        $status = PaymentStatus::tryFrom($event->status);
        $payment = Payment::query()
            ->with(['invoice.kitchen', 'invoice.supplier'])
            ->find($event->paymentId);

        if ($status === null || $payment === null || $payment->status !== $status) {
            return;
        }

        $notifications = app(NotificationDispatchService::class);

        if ($status === PaymentStatus::Submitted) {
            $notifications->toKitchenPermission(
                $payment->invoice->kitchen,
                SystemPermission::PaymentVerify,
                'Pembayaran menunggu verifikasi',
                "{$payment->number} telah diajukan untuk verifikasi.",
                Payment::class,
                $payment->getKey(),
                'warning',
                '/admin/payments',
            );
        }

        if ($status === PaymentStatus::Verified) {
            $notifications->toSupplier(
                $payment->invoice->supplier,
                'Pembayaran telah diverifikasi',
                "Pembayaran {$payment->number} sebesar Rp ".number_format((float) $payment->amount, 0, ',', '.').' telah diverifikasi.',
                Payment::class,
                $payment->getKey(),
                'success',
                '/supplier/invoices',
            );
        }
    }
}
