<?php

namespace App\Filament\Supplier\Resources\Documents\Pages;

use App\Filament\Supplier\Resources\Documents\SupplierDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierDocuments extends ManageRecords
{
    protected static string $resource = SupplierDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
