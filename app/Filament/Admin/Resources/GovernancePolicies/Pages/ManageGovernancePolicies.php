<?php

namespace App\Filament\Admin\Resources\GovernancePolicies\Pages;

use App\Filament\Admin\Resources\GovernancePolicies\GovernancePolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGovernancePolicies extends ManageRecords
{
    protected static string $resource = GovernancePolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
