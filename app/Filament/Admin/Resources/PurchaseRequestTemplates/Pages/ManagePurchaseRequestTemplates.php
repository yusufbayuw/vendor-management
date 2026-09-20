<?php

namespace App\Filament\Admin\Resources\PurchaseRequestTemplates\Pages;

use App\Filament\Admin\Resources\PurchaseRequestTemplates\PurchaseRequestTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseRequestTemplates extends ManageRecords
{
    protected static string $resource = PurchaseRequestTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Buat Template PR'),
        ];
    }
}
