<?php

namespace App\Filament\Supplier\Resources\BankAccounts\Pages;

use App\Filament\Supplier\Resources\BankAccounts\SupplierBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierBankAccounts extends ManageRecords
{
    protected static string $resource = SupplierBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
