<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class SecureFileGalleryModal
{
    public static function make(
        string $name,
        Closure $files,
        string $label = 'Lihat File',
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading('File & Bukti')
            ->modalWidth('6xl')
            ->modalContent(function (Model $record) use ($files) {
                $presentedFiles = collect($files($record))
                    ->map(function (array $file): array {
                        $metadata = SecureFilePresentation::describe($file['path'] ?? null);

                        return array_merge($file, $metadata, [
                            'label' => (string) ($file['label'] ?? $metadata['filename']),
                        ]);
                    })
                    ->values()
                    ->all();

                return view('filament.components.secure-file-gallery', [
                    'files' => $presentedFiles,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->visible(fn (Model $record): bool => collect($files($record))->isNotEmpty());
    }
}
