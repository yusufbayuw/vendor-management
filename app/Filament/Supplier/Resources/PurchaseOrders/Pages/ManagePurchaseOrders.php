<?php

namespace App\Filament\Supplier\Resources\PurchaseOrders\Pages;

use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePurchaseOrders extends ManageRecords
{
    protected static string $resource = PurchaseOrderResource::class;
}
