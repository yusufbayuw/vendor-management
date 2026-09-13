<?php

namespace App\Models;

use App\Enums\SystemRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function accessScopes(): HasMany
    {
        return $this->hasMany(UserAccessScope::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_users')
            ->withPivot(['is_owner', 'is_active'])
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->hasRole(SystemRole::SuperAdmin->value)) {
            return true;
        }

        return match ($panel->getId()) {
            'supplier' => $this->hasAnyRole([
                SystemRole::SupplierAdmin->value,
                SystemRole::SupplierOperator->value,
            ]),
            'admin' => $this->hasAnyRole([
                SystemRole::PanelUser->value,
                SystemRole::CentralManager->value,
                SystemRole::SppgManager->value,
                SystemRole::Requester->value,
                SystemRole::Procurement->value,
                SystemRole::ProcurementManager->value,
                SystemRole::Receiver->value,
                SystemRole::QualityControl->value,
                SystemRole::Finance->value,
                SystemRole::FinanceManager->value,
                SystemRole::Auditor->value,
            ]),
            default => false,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
