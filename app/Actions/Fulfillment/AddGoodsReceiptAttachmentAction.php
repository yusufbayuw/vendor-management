<?php

namespace App\Actions\Fulfillment;

use App\Enums\GoodsReceiptAttachmentType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptAttachment;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Illuminate\Support\Facades\Storage;

class AddGoodsReceiptAttachmentAction
{
    public function __construct(private readonly VendorFileStorage $files) {}

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

        $metadata = $disk === VendorFileStorage::DISK
            ? $this->files->assertSafeDocument($filePath)
            : [
                'path' => $filePath,
                'mime_type' => Storage::disk($disk)->mimeType($filePath),
                'size' => Storage::disk($disk)->size($filePath),
            ];

        return GoodsReceiptAttachment::query()->create([
            'goods_receipt_id' => $receipt->getKey(),
            'type' => $attachmentType,
            'file_path' => $metadata['path'],
            'mime_type' => $metadata['mime_type'],
            'size' => $metadata['size'],
            'caption' => $caption,
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);
    }
}
