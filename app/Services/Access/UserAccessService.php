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

    public function canAccessUser(User $actor, User $target): bool
    {
        if ($actor->is($target) || $this->hasGlobalAccess($actor)) {
            return true;
        }

        return $this->applyUserScope(User::query(), $actor)
            ->whereKey($target->getKey())
            ->exists();
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

    public function applyKitchenOwnedScope(Builder $query, User $user, string $kitchenColumn = 'sppg_kitchen_id'): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        return $query->whereIn($kitchenColumn, $this->accessibleKitchenIds($user));
    }

    public function applySupplierScope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        return $query->whereIn('id', $this->scopeIds($user, AccessScopeType::Supplier));
    }

    public function applyUserScope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        $organizationIds = $this->scopeIds($user, AccessScopeType::Organization);
        $kitchenIds = $this->accessibleKitchenIds($user);

        return $query->where(function (Builder $userQuery) use ($user, $organizationIds, $kitchenIds): void {
            $userQuery
                ->whereKey($user->getKey())
                ->orWhereHas('accessScopes', function (Builder $scopeQuery) use ($organizationIds, $kitchenIds): void {
                    $scopeQuery
                        ->where(function (Builder $organizationQuery) use ($organizationIds): void {
                            $organizationQuery
                                ->where('scope_type', AccessScopeType::Organization->value)
                                ->whereIn('scope_id', $organizationIds);
                        })
                        ->orWhere(function (Builder $kitchenQuery) use ($kitchenIds): void {
                            $kitchenQuery
                                ->where('scope_type', AccessScopeType::SppgKitchen->value)
                                ->whereIn('scope_id', $kitchenIds);
                        });
                });
        });
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

    /** @return Collection<int, int> */
    public function accessibleKitchenIds(User $user): Collection
    {
        if ($this->hasGlobalAccess($user)) {
            return SppgKitchen::query()->pluck('id')->map(static fn ($id): int => (int) $id);
        }

        $organizationIds = $this->scopeIds($user, AccessScopeType::Organization);
        $directKitchenIds = $this->scopeIds($user, AccessScopeType::SppgKitchen);

        return SppgKitchen::query()
            ->where(function (Builder $kitchenQuery) use ($organizationIds, $directKitchenIds): void {
                $kitchenQuery
                    ->whereIn('organization_id', $organizationIds)
                    ->orWhereIn('id', $directKitchenIds);
            })
            ->pluck('id')
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
