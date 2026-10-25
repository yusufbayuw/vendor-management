<?php

namespace App\Services\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

class SupplierPortalAccessService
{
    public function __construct(
        private readonly SupplierOperationalEligibilityService $eligibility,
    ) {}

    /** @return array<int, int> */
    public function activeSupplierIds(?User $user): array
    {
        return $this->suppliersFor($user)
            ->filter(fn (Supplier $supplier): bool => $this->isOperationallyEligible($supplier) && $this->userCanUsePortal($user))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Transaction-history access follows active supplier membership, not the
     * supplier's ability to accept new work. Suspended suppliers retain their
     * own historic records, but deactivated or unverified users do not.
     *
     * @return array<int, int>
     */
    public function readableSupplierIds(?User $user): array
    {
        if (! $this->userCanUsePortal($user)) {
            return [];
        }

        return $this->suppliersFor($user)
            ->filter(fn (Supplier $supplier): bool => $supplier->status === SupplierStatus::Suspended || $this->isOperationallyEligible($supplier))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function hasReadableSupplier(?User $user): bool
    {
        return $this->readableSupplierIds($user) !== [];
    }

    public function hasActiveSupplier(?User $user): bool
    {
        return $this->userCanUsePortal($user)
            && $this->suppliersFor($user)
                ->contains(fn (Supplier $supplier): bool => $this->isOperationallyEligible($supplier));
    }

    public function isOperationallyEligible(Supplier $supplier): bool
    {
        return $this->eligibility->isOperationallyEligible($supplier);
    }

    private function userCanUsePortal(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->hasVerifiedPhone();
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
