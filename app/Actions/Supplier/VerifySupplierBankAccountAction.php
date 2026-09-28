<?php

namespace App\Actions\Supplier;

use App\Enums\VerificationStatus;
use App\Models\SupplierBankAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class VerifySupplierBankAccountAction
{
    public function execute(SupplierBankAccount $account, User $actor): SupplierBankAccount
    {
        if (! in_array($account->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)) {
            throw new DomainException('Rekening tidak dapat diverifikasi pada status saat ini.');
        }

        return DB::transaction(function () use ($account, $actor): SupplierBankAccount {
            $account = SupplierBankAccount::query()->lockForUpdate()->findOrFail($account->getKey());

            if ($account->is_primary) {
                SupplierBankAccount::query()
                    ->where('supplier_id', $account->supplier_id)
                    ->whereKeyNot($account->getKey())
                    ->where('verification_status', VerificationStatus::Verified->value)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $account->forceFill([
                'verification_status' => VerificationStatus::Verified,
                'verified_at' => now(),
                'verified_by' => $actor->getKey(),
                'rejection_reason' => null,
            ])->save();

            return $account->refresh();
        }, 3);
    }
}
