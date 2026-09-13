<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'position',
        'phone',
        'email',
        'is_primary',
        'is_finance',
        'is_delivery',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_finance' => 'boolean',
            'is_delivery' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
