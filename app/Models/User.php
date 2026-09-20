<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Support\Auth\LoginIdentifier;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('phone')) {
                $user->phone_verified_at = null;
            }
        });
    }

    public function accessScopes(): HasMany
    {
        return $this->hasMany(UserAccessScope::class);
    }

    public function phoneVerificationCodes(): HasMany
    {
        return $this->hasMany(PhoneVerificationCode::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_users')
            ->withPivot(['is_owner', 'is_active'])
            ->withTimestamps();
    }

    public function hasVerifiedPhone(): bool
    {
        return filled($this->phone) && $this->phone_verified_at !== null;
    }

    public function hasOtpVerifiedPhone(): bool
    {
        if (blank($this->phone) || $this->phone_verified_at === null) {
            return false;
        }

        return $this->phoneVerificationCodes()
            ->where('phone', $this->phone)
            ->whereNotNull('verified_at')
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

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
                SystemRole::MasterDataSteward->value,
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

    protected function username(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => filled($value)
                ? Str::lower(trim($value))
                : null,
        );
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => filled($value)
                ? Str::lower(trim($value))
                : null,
        );
    }

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => LoginIdentifier::normalizePhone($value),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
