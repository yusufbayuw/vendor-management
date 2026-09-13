<?php

namespace App\Actions\Fulfillment;

use App\Enums\GoodsReceiptAttachmentType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptAttachment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

class AddGoodsReceiptAttachmentAction
{
    public function execute(
        GoodsReceipt $receipt,
        GoodsReceiptAttachmentType|string $type,
        string $filePath,
        User $actor,
        ?string $caption = null,
        string $disk = 'local',
    ): GoodsReceiptAttachment {
        $attachmentType = $type instanceof GoodsReceiptAttachmentType
            ? $type
            : GoodsReceiptAttachmentType::tryFrom($type);

        if (! $attachmentType) {
            throw new DomainException('Jenis lampiran tidak valid.');
        }

        if (! Storage::disk($disk)->exists($filePath)) {
            throw new DomainException('File lampiran tidak ditemukan pada storage.');
        }

        return GoodsReceiptAttachment::query()->create([
            'goods_receipt_id' => $receipt->getKey(),
            'type' => $attachmentType,
            'file_path' => $filePath,
            'mime_type' => Storage::disk($disk)->mimeType($filePath),
            'size' => Storage::disk($disk)->size($filePath),
            'caption' => $caption,
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);
    }
}
