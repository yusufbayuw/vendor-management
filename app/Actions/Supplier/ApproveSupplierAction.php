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

        $hasActiveOwner = $supplier->users()
            ->wherePivot('is_active', true)
            ->wherePivot('is_owner', true)
            ->exists();

        $verifiedPicQuery = $supplier->users()
            ->wherePivot('is_active', true)
            ->whereNotNull('users.phone')
            ->whereNotNull('users.phone_verified_at');

        if ($hasActiveOwner) {
            $verifiedPicQuery->wherePivot('is_owner', true);
        }

        if (! $verifiedPicQuery->exists()) {
            throw new DomainException(
                $hasActiveOwner
                    ? 'Nomor HP PIC/owner supplier harus diverifikasi sebelum supplier dapat disetujui.'
                    : 'Minimal satu pengguna aktif supplier harus memiliki nomor HP terverifikasi sebelum supplier dapat disetujui.',
            );
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
