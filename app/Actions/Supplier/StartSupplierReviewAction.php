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

            $documents = $supplier->documents()
                ->where('status', SupplierDocumentStatus::Uploaded->value)
                ->lockForUpdate()
                ->get();

            foreach ($documents as $document) {
                $document->update(['status' => SupplierDocumentStatus::UnderReview]);
            }

            return $supplier->refresh();
        });
    }
}
