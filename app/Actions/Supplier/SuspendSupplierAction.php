<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use DomainException;

class SuspendSupplierAction
{
    public function execute(Supplier $supplier, string $reason): Supplier
    {
        if ($supplier->status !== SupplierStatus::Active) {
            throw new DomainException('Hanya supplier aktif yang dapat ditangguhkan.');
        }

        if (blank($reason)) {
            throw new DomainException('Alasan penangguhan wajib diisi.');
        }

        $supplier->forceFill([
            'status' => SupplierStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        $supplier->approvalAttestation()->delete();

        return $supplier->refresh();
    }
}
