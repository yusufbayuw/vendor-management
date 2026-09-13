<?php

namespace App\Actions\Supplier;

use App\Enums\VerificationStatus;
use App\Models\SupplierBankAccount;
use App\Models\User;
use DomainException;

class RejectSupplierBankAccountAction
{
    public function execute(SupplierBankAccount $account, User $actor, string $reason): SupplierBankAccount
    {
        if (! in_array($account->verification_status, [VerificationStatus::Pending, VerificationStatus::Verified], true)) {
            throw new DomainException('Rekening tidak dapat ditolak pada status saat ini.');
        }

        if (blank($reason)) {
            throw new DomainException('Alasan penolakan rekening wajib diisi.');
        }

        $account->forceFill([
            'verification_status' => VerificationStatus::Rejected,
            'verified_at' => now(),
            'verified_by' => $actor->getKey(),
            'rejection_reason' => $reason,
        ])->save();

        return $account->refresh();
    }
}
