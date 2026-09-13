<?php

namespace App\Actions\Payment;

use App\Enums\PaymentAttachmentType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\User;
use DomainException;

class AttachPaymentProofAction
{
    public function execute(
        Payment $payment,
        string $filePath,
        User $actor,
        PaymentAttachmentType $type = PaymentAttachmentType::PaymentProof,
        ?string $mimeType = null,
        ?int $size = null,
        ?string $caption = null,
    ): PaymentAttachment {
        if (! in_array($payment->status, [PaymentStatus::Draft, PaymentStatus::Submitted, PaymentStatus::UnderReview], true)) {
            throw new DomainException('Bukti pembayaran tidak dapat diubah setelah pembayaran selesai diverifikasi.');
        }

        if (blank($filePath)) {
            throw new DomainException('File bukti pembayaran wajib tersedia.');
        }

        return PaymentAttachment::query()->create([
            'payment_id' => $payment->getKey(),
            'type' => $type,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'size' => $size,
            'caption' => $caption,
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);
    }
}
