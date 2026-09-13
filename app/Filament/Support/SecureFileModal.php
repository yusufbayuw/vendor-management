<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class SecureFileModal
{
    public static function make(
        string $name,
        Closure $inlineUrl,
        Closure $downloadUrl,
        Closure $path,
        string $label = 'Lihat File',
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading('Preview File')
            ->modalWidth('5xl')
            ->modalContent(fn (Model $record) => view('filament.components.secure-file-preview', [
                'inlineUrl' => $inlineUrl($record),
                'downloadUrl' => $downloadUrl($record),
                'path' => $path($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->visible(fn (Model $record): bool => filled($path($record)));
    }
}
