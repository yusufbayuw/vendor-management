<?php

namespace App\Filament\Admin\Resources\SppgKitchens\Pages;

use App\Filament\Admin\Resources\SppgKitchens\SppgKitchenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSppgKitchens extends ManageRecords
{
    protected static string $resource = SppgKitchenResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
