<?php

namespace App\Actions\Payment;

use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\GovernanceProcess;
use App\Enums\PaymentAttachmentType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitPaymentForVerificationAction
{
    public function __construct(private readonly CreateApprovalRequestAction $createApprovalRequest) {}

    public function execute(Payment $payment, User $actor): Payment
    {
        if ($payment->status !== PaymentStatus::Draft) {
            throw new DomainException('Hanya pembayaran draft yang dapat diajukan untuk verifikasi.');
        }

        $payment->loadMissing(['attachments', 'invoice.kitchen.organization']);

        if (! $payment->attachments->contains(fn ($attachment) => $attachment->type === PaymentAttachmentType::PaymentProof)) {
            throw new DomainException('Bukti pembayaran wajib diunggah sebelum verifikasi.');
        }

        return DB::transaction(function () use ($payment, $actor): Payment {
            $payment->update(['status' => PaymentStatus::Submitted]);

            $this->createApprovalRequest->execute(
                $payment,
                $payment->invoice->kitchen->organization,
                GovernanceProcess::PaymentVerification,
                $actor,
                (float) $payment->amount,
            );

            return $payment->refresh();
        });
    }
}
