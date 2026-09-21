<?php

namespace App\Filament\Admin\Resources\LegacyImportBatches\Pages;

use App\Filament\Admin\Resources\LegacyImportBatches\LegacyImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegacyImportBatches extends ListRecords
{
    protected static string $resource = LegacyImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Import File Lama'),
        ];
    }
}
