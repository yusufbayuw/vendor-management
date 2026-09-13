<?php

namespace App\Actions\Supplier;

use App\Enums\VerificationStatus;
use App\Models\SupplierBankAccount;
use App\Models\User;
use DomainException;

class VerifySupplierBankAccountAction
{
    public function execute(SupplierBankAccount $account, User $actor): SupplierBankAccount
    {
        if (! in_array($account->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)) {
            throw new DomainException('Rekening tidak dapat diverifikasi pada status saat ini.');
        }

        $account->forceFill([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $actor->getKey(),
            'rejection_reason' => null,
        ])->save();

        return $account->refresh();
    }
}
