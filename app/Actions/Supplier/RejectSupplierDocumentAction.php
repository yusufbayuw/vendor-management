<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Models\SupplierDocument;
use App\Models\User;
use DomainException;

class RejectSupplierDocumentAction
{
    public function execute(SupplierDocument $document, User $actor, string $reason): SupplierDocument
    {
        if (! in_array($document->status, [SupplierDocumentStatus::Uploaded, SupplierDocumentStatus::UnderReview], true)) {
            throw new DomainException('Dokumen tidak dapat diberi catatan perbaikan pada status saat ini.');
        }

        if (blank($reason)) {
            throw new DomainException('Keterangan verifikasi wajib diisi.');
        }

        $document->forceFill([
            'status' => SupplierDocumentStatus::Rejected,
            'verified_at' => now(),
            'verified_by' => $actor->getKey(),
            'rejection_reason' => $reason,
            'verification_note' => $reason,
        ])->save();

        return $document->refresh();
    }
}
