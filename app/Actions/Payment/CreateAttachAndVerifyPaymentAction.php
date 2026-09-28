<?php

namespace App\Actions\Payment;

use App\Enums\OperationalProfile;
use App\Enums\PaymentMethod;
use App\Enums\SystemPermission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupplierBankAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAttachAndVerifyPaymentAction
{
    public function __construct(
        private readonly CreatePaymentAction $createPayment,
        private readonly AttachPaymentProofAction $attachPaymentProof,
        private readonly SubmitAndVerifyPaymentAction $submitAndVerifyPayment,
    ) {}

    public function execute(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        User $actor,
        string $paymentProof,
        Carbon|string|null $paymentDate = null,
        ?string $sourceBankName = null,
        ?string $referenceNumber = null,
        ?SupplierBankAccount $destinationAccount = null,
        ?string $notes = null,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): Payment {
        $invoice->loadMissing('kitchen.organization');

        if ($invoice->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Pembayaran satu langkah hanya tersedia untuk profil Lean.');
        }

        if (! $actor->can(SystemPermission::PaymentCreate->value)
            || ! $actor->can(SystemPermission::PaymentVerify->value)) {
            throw new DomainException('User harus memiliki izin membuat dan memverifikasi pembayaran.');
        }

        if (blank($paymentProof)) {
            throw new DomainException('Bukti pembayaran wajib tersedia.');
        }

        return DB::transaction(function () use (
            $invoice,
            $amount,
            $method,
            $actor,
            $paymentProof,
            $paymentDate,
            $sourceBankName,
            $referenceNumber,
            $destinationAccount,
            $notes,
            $comments,
            $overrideReason,
        ): Payment {
            $payment = $this->createPayment->execute(
                $invoice,
                $amount,
                $method,
                $actor,
                $paymentDate,
                $sourceBankName,
                $referenceNumber,
                $destinationAccount,
                $notes,
            );

            $this->attachPaymentProof->execute($payment, $paymentProof, $actor);

            return $this->submitAndVerifyPayment->execute(
                $payment,
                $actor,
                $comments,
                $overrideReason,
            );
        }, 3);
    }
}
