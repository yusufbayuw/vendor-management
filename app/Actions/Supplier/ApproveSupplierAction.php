<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierDocumentStatus;
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

        $documents = $supplier->documents()->get();

        if ($documents->isEmpty()) {
            throw new DomainException('Minimal satu dokumen legal harus tersedia sebelum supplier dapat disetujui.');
        }

        $hasUnverifiedDocument = $documents->contains(
            static fn ($document): bool => blank($document->file_path)
                || $document->status !== SupplierDocumentStatus::Verified
                || ($document->expires_at !== null && $document->expires_at->isPast()),
        );

        if ($hasUnverifiedDocument) {
            throw new DomainException('Seluruh dokumen legal yang diajukan harus sudah diperiksa, masih berlaku, dan berstatus terverifikasi sebelum supplier dapat disetujui.');
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
