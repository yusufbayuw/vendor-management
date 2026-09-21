<?php

namespace App\Services\Supplier;

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
        return $user !== null && $user->hasOtpVerifiedPhone();
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
