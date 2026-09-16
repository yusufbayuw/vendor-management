<?php

namespace App\Services\Files;

use DomainException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VendorFileStorage
{
    public const DISK = 'vendor-private';

    public const MAX_DOCUMENT_SIZE = 10 * 1024 * 1024;

    /** @var array<string> */
    public const DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** @var array<string, array<int, string>> */
    private const MIME_EXTENSIONS = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @var array<string> */
    private const LEGACY_DISKS = ['local', 'public'];

    public function ensurePrivate(?string $path): bool
    {
        $path = $this->normalizePath($path);

        if ($path === null) {
            return false;
        }

        $private = $this->disk();

        if ($private->exists($path)) {
            return true;
        }

        foreach (self::LEGACY_DISKS as $legacyDisk) {
            $legacy = Storage::disk($legacyDisk);

            if (! $legacy->exists($path)) {
                continue;
            }

            $stream = $legacy->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException('File lama tidak dapat dibaca.');
            }

            try {
                if (! $private->writeStream($path, $stream)) {
                    throw new RuntimeException('File tidak dapat dipindahkan ke penyimpanan private.');
                }
            } finally {
                fclose($stream);
            }

            if (! $private->exists($path)) {
                throw new RuntimeException('Verifikasi pemindahan file private gagal.');
            }

            $legacy->delete($path);

            return true;
        }

        return false;
    }

    /**
     * @return array{path:string,filename:string,mime_type:string,size:int|null,preview_type:string}
     */
    public function inspect(string $path): array
    {
        $path = $this->requiredPath($path);

        if (! $this->disk()->exists($path)) {
            throw new RuntimeException('File tidak ditemukan pada penyimpanan private.');
        }

        $mime = $this->mimeType($path);

        return [
            'path' => $path,
            'filename' => basename($path),
            'mime_type' => $mime,
            'size' => $this->size($path),
            'preview_type' => match (true) {
                str_starts_with($mime, 'image/') && $this->isInlinePreviewable($mime) => 'image',
                $mime === 'application/pdf' => 'pdf',
                default => 'download',
            },
        ];
    }

    /**
     * Validate a newly uploaded business document using server-detected MIME and size.
     *
     * @return array{path:string,filename:string,mime_type:string,size:int|null,preview_type:string}
     */
    public function assertSafeDocument(string $path, int $maxBytes = self::MAX_DOCUMENT_SIZE): array
    {
        try {
            $metadata = $this->inspect($path);
        } catch (RuntimeException $exception) {
            throw new DomainException('File upload tidak ditemukan atau tidak dapat dibaca.', previous: $exception);
        }

        if (! in_array($metadata['mime_type'], self::DOCUMENT_MIME_TYPES, true)) {
            $this->delete($path);

            throw new DomainException('Tipe file tidak diizinkan. Gunakan PDF, JPG/JPEG, PNG, atau WebP.');
        }

        if (($metadata['size'] ?? 0) > $maxBytes) {
            $this->delete($path);

            throw new DomainException('Ukuran file melebihi batas 10 MB.');
        }

        $extension = strtolower(pathinfo($metadata['filename'], PATHINFO_EXTENSION));
        $allowedExtensions = self::MIME_EXTENSIONS[$metadata['mime_type']] ?? [];

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            $this->delete($path);

            throw new DomainException('Ekstensi file tidak sesuai dengan isi file yang terdeteksi.');
        }

        return $metadata;
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }

    public function absolutePath(string $path): string
    {
        return $this->disk()->path($this->requiredPath($path));
    }

    public function mimeType(string $path): string
    {
        $mime = $this->disk()->mimeType($this->requiredPath($path));

        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    public function size(string $path): ?int
    {
        $size = $this->disk()->size($this->requiredPath($path));

        return is_int($size) ? $size : null;
    }

    public function delete(?string $path): void
    {
        $path = $this->normalizePath($path);

        if ($path === null) {
            return;
        }

        $this->disk()->delete($path);

        foreach (self::LEGACY_DISKS as $legacyDisk) {
            Storage::disk($legacyDisk)->delete($path);
        }
    }

    public function isInlinePreviewable(string $mime): bool
    {
        return in_array($mime, self::DOCUMENT_MIME_TYPES, true);
    }

    private function requiredPath(string $path): string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            throw new RuntimeException('Path file tidak valid.');
        }

        return $normalized;
    }

    private function normalizePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = ltrim(trim($path), '/');

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '../')) {
            return null;
        }

        return $path;
    }
}
