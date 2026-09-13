<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

class StartSupplierReviewAction
{
    public function execute(Supplier $supplier): Supplier
    {
        if ($supplier->status !== SupplierStatus::Submitted) {
            throw new DomainException('Verifikasi hanya dapat dimulai untuk supplier yang sudah diajukan.');
        }

        return DB::transaction(function () use ($supplier): Supplier {
            $supplier->update(['status' => SupplierStatus::UnderReview]);

            $supplier->documents()
                ->where('status', SupplierDocumentStatus::Uploaded->value)
                ->update(['status' => SupplierDocumentStatus::UnderReview->value]);

            return $supplier->refresh();
        });
    }
}
