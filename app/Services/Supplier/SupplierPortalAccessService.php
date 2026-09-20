<?php

namespace App\Services\Supplier;

use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

class SupplierPortalAccessService
{
    public function __construct(
        private readonly SupplierApprovalAttestationService $attestations,
    ) {}

    /** @return array<int, int> */
    public function activeSupplierIds(?User $user): array
    {
        return $this->suppliersFor($user)
            ->filter(fn (Supplier $supplier): bool => $this->isOperationallyEligible($supplier))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function hasActiveSupplier(?User $user): bool
    {
        return $this->suppliersFor($user)
            ->contains(fn (Supplier $supplier): bool => $this->isOperationallyEligible($supplier));
    }

    public function isOperationallyEligible(Supplier $supplier): bool
    {
        if (
            $supplier->status !== SupplierStatus::Active
            || $supplier->verified_at === null
            || $supplier->verified_by === null
            || $supplier->activated_at === null
            || ! $this->attestations->isValid($supplier)
        ) {
            return false;
        }

        $supplier->loadMissing(['documents', 'users.phoneVerificationCodes']);

        if ($supplier->documents->isEmpty()) {
            return false;
        }

        $documentsValid = $supplier->documents->every(
            static fn ($document): bool => filled($document->file_path)
                && $document->status === SupplierDocumentStatus::Verified
                && $document->verified_at !== null
                && $document->verified_by !== null
                && ($document->expires_at === null || ! $document->expires_at->isPast()),
        );

        if (! $documentsValid) {
            return false;
        }

        $activeUsers = $supplier->users->filter(
            static fn (User $user): bool => (bool) $user->pivot?->is_active,
        );
        $owners = $activeUsers->filter(
            static fn (User $user): bool => (bool) $user->pivot?->is_owner,
        );
        $candidates = $owners->isNotEmpty() ? $owners : $activeUsers;

        return $candidates->contains(
            static fn (User $user): bool => $user->hasOtpVerifiedPhone(),
        );
    }

    public function currentSupplier(?User $user): ?Supplier
    {
        return $this->suppliersFor($user)->first();
    }

    /** @return Collection<int, Supplier> */
    private function suppliersFor(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->with(['approvalAttestation', 'documents', 'users.phoneVerificationCodes'])
            ->orderByPivot('is_owner', 'desc')
            ->orderBy('suppliers.id')
            ->get();
    }
}
