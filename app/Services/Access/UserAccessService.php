<?php

namespace App\Services\Access;

use App\Enums\AccessScopeType;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Models\UserAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UserAccessService
{
    public function hasGlobalAccess(User $user): bool
    {
        return $this->hasScope($user, AccessScopeType::Global, 0);
    }

    public function canAccessOrganization(User $user, int $organizationId): bool
    {
        if ($this->hasGlobalAccess($user)) {
            return true;
        }

        if ($this->hasScope($user, AccessScopeType::Organization, $organizationId)) {
            return true;
        }

        return SppgKitchen::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $this->scopeIds($user, AccessScopeType::SppgKitchen))
            ->exists();
    }

    public function canAccessKitchen(User $user, SppgKitchen|int $kitchen): bool
    {
        $kitchenModel = $kitchen instanceof SppgKitchen
            ? $kitchen
            : SppgKitchen::query()->findOrFail($kitchen);

        return $this->hasGlobalAccess($user)
            || $this->hasScope($user, AccessScopeType::Organization, $kitchenModel->organization_id)
            || $this->hasScope($user, AccessScopeType::SppgKitchen, $kitchenModel->getKey());
    }

    public function canAccessSupplier(User $user, int $supplierId): bool
    {
        return $this->hasGlobalAccess($user)
            || $this->hasScope($user, AccessScopeType::Supplier, $supplierId);
    }

    public function applyOrganizationScope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        $organizationIds = $this->scopeIds($user, AccessScopeType::Organization);
        $kitchenOrganizationIds = SppgKitchen::query()
            ->whereIn('id', $this->scopeIds($user, AccessScopeType::SppgKitchen))
            ->pluck('organization_id');

        return $query->whereIn(
            'id',
            $organizationIds->merge($kitchenOrganizationIds)->unique()->values(),
        );
    }

    public function applyKitchenScope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        $organizationIds = $this->scopeIds($user, AccessScopeType::Organization);
        $kitchenIds = $this->scopeIds($user, AccessScopeType::SppgKitchen);

        return $query->where(function (Builder $scopeQuery) use ($organizationIds, $kitchenIds): void {
            $scopeQuery
                ->whereIn('organization_id', $organizationIds)
                ->orWhereIn('id', $kitchenIds);
        });
    }

    public function applySupplierScope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        $supplierIds = $this->scopeIds($user, AccessScopeType::Supplier);

        if ($supplierIds->isNotEmpty()) {
            return $query->whereIn('id', $supplierIds);
        }

        return $query;
    }

    /** @return Collection<int, int> */
    public function scopeIds(User $user, AccessScopeType $type): Collection
    {
        return UserAccessScope::query()
            ->where('user_id', $user->getKey())
            ->where('scope_type', $type->value)
            ->pluck('scope_id')
            ->map(static fn ($id): int => (int) $id);
    }

    private function hasScope(User $user, AccessScopeType $type, int $scopeId): bool
    {
        return UserAccessScope::query()
            ->where('user_id', $user->getKey())
            ->where('scope_type', $type->value)
            ->where('scope_id', $scopeId)
            ->exists();
    }
}
