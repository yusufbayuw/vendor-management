<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'bank_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder',
        'is_primary',
        'verification_status',
        'verified_at',
        'verified_by',
        'rejection_reason',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (! $account->bank_id) {
                return;
            }

            $bank = Bank::query()->find($account->bank_id);

            if (! $bank) {
                return;
            }

            $account->bank_code = $bank->code;
            $account->bank_name = $bank->name;
        });
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
