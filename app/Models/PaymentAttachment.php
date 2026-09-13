<?php

namespace App\Models;

use App\Enums\PaymentAttachmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'type',
        'file_path',
        'mime_type',
        'size',
        'caption',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentAttachmentType::class,
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
