<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Models\SupplierDocument;
use App\Models\User;
use DomainException;

class VerifySupplierDocumentAction
{
    public function execute(SupplierDocument $document, User $actor, ?string $note = null): SupplierDocument
    {
        if (! in_array($document->status, [
            SupplierDocumentStatus::Uploaded,
            SupplierDocumentStatus::UnderReview,
            SupplierDocumentStatus::Rejected,
        ], true)) {
            throw new DomainException('Dokumen tidak dapat diverifikasi pada status saat ini.');
        }

        $document->forceFill([
            'status' => SupplierDocumentStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $actor->getKey(),
            'rejection_reason' => null,
            'verification_note' => filled($note)
                ? trim((string) $note)
                : 'Dokumen diperiksa dan dicatat sesuai.',
        ])->save();

        return $document->refresh();
    }
}
