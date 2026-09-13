<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Enums\SystemPermission;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesMasterData
{
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::MasterDataView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
