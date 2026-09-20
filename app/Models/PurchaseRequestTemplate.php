<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequestTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_kitchen_id',
        'created_by',
        'name',
        'description',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(SppgKitchen::class, 'sppg_kitchen_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
