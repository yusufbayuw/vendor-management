<?php

namespace App\Filament\Supplier\Resources\Products\Pages;

use App\Filament\Supplier\Resources\Products\SupplierProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierProducts extends ManageRecords
{
    protected static string $resource = SupplierProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
