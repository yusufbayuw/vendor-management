<?php

namespace App\Models;

use App\Enums\GoodsReceiptAttachmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receipt_id',
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
            'type' => GoodsReceiptAttachmentType::class,
            'size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
