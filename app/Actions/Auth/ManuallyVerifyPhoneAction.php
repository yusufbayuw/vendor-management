<?php

namespace App\Actions\Auth;

use App\Enums\SystemPermission;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ManuallyVerifyPhoneAction
{
    public function __construct(
        private readonly UserAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function execute(User $target, User $actor, string $reason): User
    {
        if (! $actor->is_active
            || ! $actor->can(SystemPermission::UserManage->value)
            || ! $this->access->canAccessUser($actor, $target)
            || $actor->is($target)) {
            throw new DomainException('Tidak memiliki kewenangan memverifikasi nomor HP pengguna ini.');
        }

        if (blank(trim($reason))) {
            throw new DomainException('Alasan verifikasi manual wajib diisi.');
        }

        return DB::transaction(function () use ($target, $actor, $reason): User {
            $user = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (blank($user->phone) || $user->hasVerifiedPhone()) {
                throw new DomainException('Nomor HP kosong atau sudah terverifikasi.');
            }

            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verification_method' => 'admin_manual',
                'phone_verified_by' => $actor->getKey(),
            ])->save();

            $this->audit->record($user, 'phone_verified_manually', [], [
                'verified_by' => $actor->getKey(),
                'verification_reason' => trim($reason),
                'phone_verification_method' => 'admin_manual',
            ]);

            return $user->refresh();
        });
    }
}
