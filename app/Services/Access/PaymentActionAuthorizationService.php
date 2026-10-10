<?php

namespace App\Services\Access;

use App\Enums\SystemPermission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use DomainException;

class PaymentActionAuthorizationService
{
    public function __construct(private readonly UserAccessService $access) {}

    public function assertForInvoice(Invoice $invoice, User $actor, SystemPermission $permission): void
    {
        if (! $actor->is_active || ! $actor->can($permission->value)
            || ! $this->access->canAccessKitchen($actor, (int) $invoice->sppg_kitchen_id)) {
            throw new DomainException('User tidak berwenang mengubah pembayaran pada dapur ini.');
        }
    }

    public function assertForPayment(Payment $payment, User $actor, SystemPermission $permission): void
    {
        $this->assertForInvoice($payment->invoice, $actor, $permission);
    }
}
