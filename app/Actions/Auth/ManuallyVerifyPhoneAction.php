<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;

class ManuallyVerifyPhoneAction
{
    public function __construct(private readonly AuditService $audit) {}

    public function execute(User $user, User $actor, string $reason): User
    {
        if (blank($user->phone)) {
            throw new DomainException('Pengguna belum memiliki nomor HP.');
        }

        if (blank(trim($reason))) {
            throw new DomainException('Alasan verifikasi manual wajib diisi.');
        }

        $oldVerifiedAt = $user->phone_verified_at;
        $verifiedAt = now();

        $user->forceFill(['phone_verified_at' => $verifiedAt])->save();

        $this->audit->record(
            $user,
            'phone_verified_manually',
            ['phone_verified_at' => $oldVerifiedAt?->toISOString()],
            [
                'phone' => $user->phone,
                'phone_verified_at' => $verifiedAt->toISOString(),
                'reason' => trim($reason),
                'verified_by' => $actor->getKey(),
            ],
        );

        return $user->refresh();
    }
}
