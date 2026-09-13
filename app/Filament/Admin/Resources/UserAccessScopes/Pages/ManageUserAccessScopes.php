<?php

namespace App\Filament\Admin\Resources\UserAccessScopes\Pages;

use App\Filament\Admin\Resources\UserAccessScopes\UserAccessScopeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserAccessScopes extends ManageRecords
{
    protected static string $resource = UserAccessScopeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
