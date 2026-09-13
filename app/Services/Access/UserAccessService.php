<?php

namespace App\Services\Access;

use App\Enums\AccessScopeType;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Models\UserAccessScope;

class UserAccessService
{
    public function hasGlobalAccess(User $user): bool
    {
        return $this->hasScope($user, AccessScopeType::Global, 0);
    }

    public function canAccessOrganization(User $user, int $organizationId): bool
    {
        return $this->hasGlobalAccess($user)
            || $this->hasScope($user, AccessScopeType::Organization, $organizationId);
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

    private function hasScope(User $user, AccessScopeType $type, int $scopeId): bool
    {
        return UserAccessScope::query()
            ->where('user_id', $user->getKey())
            ->where('scope_type', $type->value)
            ->where('scope_id', $scopeId)
            ->exists();
    }
}
