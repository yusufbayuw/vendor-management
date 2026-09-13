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
            ->modalContent(fn (Model $record) => view('filament.components.secure-file-gallery', [
                'files' => collect($files($record))->values()->all(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->visible(fn (Model $record): bool => collect($files($record))->isNotEmpty());
    }
}
