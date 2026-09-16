<?php

namespace App\Actions\Payment;

use App\Enums\PaymentAttachmentType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use DomainException;

class AttachPaymentProofAction
{
    public function __construct(private readonly VendorFileStorage $files) {}

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

        $metadata = $this->files->assertSafeDocument($filePath);

        return PaymentAttachment::query()->create([
            'payment_id' => $payment->getKey(),
            'type' => $type,
            'file_path' => $metadata['path'],
            'mime_type' => $metadata['mime_type'],
            'size' => $metadata['size'],
            'caption' => $caption,
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);
    }
}
