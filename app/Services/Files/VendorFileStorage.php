<?php

namespace App\Services\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VendorFileStorage
{
    public const DISK = 'vendor-private';

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
        return in_array($mime, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
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
