<?php

namespace App\Services\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;

class SupplierPortalAccessService
{
    /** @return array<int, int> */
    public function activeSupplierIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->where('suppliers.status', SupplierStatus::Active->value)
            ->pluck('suppliers.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function hasActiveSupplier(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->where('suppliers.status', SupplierStatus::Active->value)
            ->exists();
    }

    public function currentSupplier(?User $user): ?Supplier
    {
        if ($user === null) {
            return null;
        }

        return $user->suppliers()
            ->wherePivot('is_active', true)
            ->orderByPivot('is_owner', 'desc')
            ->orderBy('suppliers.id')
            ->first();
    }
}
