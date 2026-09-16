<?php

namespace App\Filament\Support;

use App\Services\Files\VendorFileStorage;
use Throwable;

class SecureFilePresentation
{
    /**
     * @return array{path:?string,filename:string,mimeType:string,size:?int,type:string,available:bool}
     */
    public static function describe(?string $path): array
    {
        $filename = basename((string) $path) ?: 'file';
        $fallback = [
            'path' => $path,
            'filename' => $filename,
            'mimeType' => 'application/octet-stream',
            'size' => null,
            'type' => 'download',
            'available' => false,
        ];

        if (blank($path)) {
            return $fallback;
        }

        try {
            $storage = app(VendorFileStorage::class);

            if (! $storage->ensurePrivate($path)) {
                return $fallback;
            }

            $metadata = $storage->inspect($path);

            return [
                'path' => $metadata['path'],
                'filename' => $metadata['filename'],
                'mimeType' => $metadata['mime_type'],
                'size' => $metadata['size'],
                'type' => $metadata['preview_type'],
                'available' => true,
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }
}
