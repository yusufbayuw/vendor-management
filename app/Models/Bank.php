<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $bank): void {
            if (! $bank->wasChanged(['code', 'name'])) {
                return;
            }

            $bank->supplierBankAccounts()->update([
                'bank_code' => $bank->code,
                'bank_name' => $bank->name,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function supplierBankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function activeOptions(): array
    {
        return static::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (self $bank): array => [
                $bank->getKey() => $bank->name.' — '.$bank->code,
            ])
            ->all();
    }
}
