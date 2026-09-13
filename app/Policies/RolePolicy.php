<?php

namespace App\Policies;

use App\Enums\SystemPermission;
use App\Enums\SystemRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->canManage($user) && ! $this->isSystemRole($role);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(SystemRole::SuperAdmin->value)
            || $user->can(SystemPermission::GovernanceManage->value);
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array(
            $role->name,
            array_map(static fn (SystemRole $systemRole): string => $systemRole->value, SystemRole::cases()),
            true,
        );
    }
}
