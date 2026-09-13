<?php

namespace App\Models;

use App\Enums\SupplierStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'legal_name',
        'display_name',
        'supplier_type',
        'npwp',
        'nib',
        'email',
        'phone',
        'website',
        'address',
        'province_code',
        'regency_code',
        'district_code',
        'village_code',
        'postal_code',
        'status',
        'submitted_at',
        'verified_at',
        'verified_by',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supplier_users')
            ->withPivot(['is_owner', 'is_active'])
            ->withTimestamps();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }
}
