<?php

namespace App\Models;

use App\Enums\AccessScopeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => AccessScopeType::class,
            'scope_id' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
