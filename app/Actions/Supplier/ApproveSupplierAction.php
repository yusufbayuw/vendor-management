<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveSupplierAction
{
    public function execute(Supplier $supplier, User $actor): Supplier
    {
        if (! in_array($supplier->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true)) {
            throw new DomainException('Supplier hanya dapat disetujui saat berstatus diajukan atau dalam verifikasi.');
        }

        return DB::transaction(function () use ($supplier, $actor): Supplier {
            $supplier->forceFill([
                'status' => SupplierStatus::Active,
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'activated_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            return $supplier->refresh();
        });
    }
}
