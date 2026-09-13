<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use DomainException;

class StartSupplierReviewAction
{
    public function execute(Supplier $supplier): Supplier
    {
        if ($supplier->status !== SupplierStatus::Submitted) {
            throw new DomainException('Verifikasi hanya dapat dimulai untuk supplier yang sudah diajukan.');
        }

        $supplier->update(['status' => SupplierStatus::UnderReview]);

        return $supplier->refresh();
    }
}
