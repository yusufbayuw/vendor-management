<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use DomainException;

class SubmitSupplierAction
{
    public function execute(Supplier $supplier): Supplier
    {
        if (! in_array($supplier->status, [SupplierStatus::Draft, SupplierStatus::RevisionRequired], true)) {
            throw new DomainException('Supplier hanya dapat diajukan dari status draft atau perlu perbaikan.');
        }

        if (blank($supplier->legal_name) || blank($supplier->phone)) {
            throw new DomainException('Nama legal dan nomor HP supplier wajib dilengkapi sebelum pengajuan.');
        }

        $supplier->forceFill([
            'status' => SupplierStatus::Submitted,
            'submitted_at' => now(),
        ])->save();

        return $supplier->refresh();
    }
}
