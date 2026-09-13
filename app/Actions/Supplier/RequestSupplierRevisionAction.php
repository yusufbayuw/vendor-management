<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use DomainException;

class RequestSupplierRevisionAction
{
    public function execute(Supplier $supplier, string $reason): Supplier
    {
        if (! in_array($supplier->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true)) {
            throw new DomainException('Permintaan perbaikan hanya dapat dibuat selama proses verifikasi supplier.');
        }

        if (blank($reason)) {
            throw new DomainException('Alasan perbaikan wajib diisi.');
        }

        $supplier->forceFill([
            'status' => SupplierStatus::RevisionRequired,
            'notes' => $reason,
        ])->save();

        return $supplier->refresh();
    }
}
