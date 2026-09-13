<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'code_hash',
        'ip_address',
        'attempt_count',
        'expires_at',
        'verified_at',
        'sent_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
