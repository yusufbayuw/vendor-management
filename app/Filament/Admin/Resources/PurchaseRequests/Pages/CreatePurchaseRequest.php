<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Enums\PurchaseRequestStatus;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\SppgKitchen;
use App\Services\Access\UserAccessService;
use App\Services\Documents\DocumentNumberService;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $kitchen = SppgKitchen::query()->findOrFail($data['sppg_kitchen_id']);
        abort_unless(app(UserAccessService::class)->canAccessKitchen($user, $kitchen), 403);

        $data['number'] = app(DocumentNumberService::class)->next('PR', $kitchen);
        $data['requested_by'] = $user->getKey();
        $data['status'] = PurchaseRequestStatus::Draft->value;

        return $data;
    }
}
