<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierApprovalAttestationService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveSupplierAction
{
    public function __construct(
        private readonly SupplierApprovalAttestationService $attestations,
    ) {}

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
                || $document->verified_at === null
                || $document->verified_by === null
                || ($document->expires_at !== null && $document->expires_at->isPast()),
        );

        if ($hasUnverifiedDocument) {
            throw new DomainException('Seluruh dokumen legal yang diajukan harus sudah diperiksa, masih berlaku, dan berstatus terverifikasi sebelum supplier dapat disetujui.');
        }

        $activeUsers = $supplier->users()
            ->wherePivot('is_active', true)
            ->with('phoneVerificationCodes')
            ->get();
        $owners = $activeUsers->filter(
            static fn (User $user): bool => (bool) $user->pivot?->is_owner,
        );
        $candidates = $owners->isNotEmpty() ? $owners : $activeUsers;

        if (! $candidates->contains(static fn (User $user): bool => $user->hasOtpVerifiedPhone())) {
            throw new DomainException(
                $owners->isNotEmpty()
                    ? 'Nomor HP PIC/owner supplier harus diverifikasi melalui OTP sebelum supplier dapat disetujui.'
                    : 'Minimal satu pengguna aktif supplier harus memiliki nomor HP yang diverifikasi melalui OTP sebelum supplier dapat disetujui.',
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

            $this->attestations->issue($supplier, $actor);

            return $supplier->refresh();
        });
    }
}
