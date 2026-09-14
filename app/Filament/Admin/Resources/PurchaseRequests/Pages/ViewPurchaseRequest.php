<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\PurchaseRequests\RelationManagers\ItemsRelationManager;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getAllRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
