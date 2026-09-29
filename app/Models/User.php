<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Notifications\VerifySupplierEmail;
use App\Support\Auth\LoginIdentifier;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

#[Fillable(['name', 'username', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, MustVerifyEmailTrait, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('phone')) {
                $user->phone_verified_at = null;
                $user->phone_verification_method = null;
                $user->phone_verified_by = null;
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
        });

        static::updated(function (User $user): void {
            if (! $user->wasChanged('email') || blank($user->email) || $user->hasVerifiedEmail()) {
                return;
            }

            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $exception) {
                report($exception);
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

    public function phoneVerificationRequests(): HasMany
    {
        return $this->hasMany(PhoneVerificationRequest::class);
    }

    public function phoneVerifier(): BelongsTo
    {
        return $this->belongsTo(self::class, 'phone_verified_by');
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

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifySupplierEmail);
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
