<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Services\Access\UserAccessService;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    private bool $submitAfterCreate = false;

    protected function getFormActions(): array
    {
        return [
            Action::make('createAndSubmit')
                ->label('Simpan & Ajukan')
                ->color('primary')
                ->icon('heroicon-o-paper-airplane')
                ->action(fn () => $this->createAndSubmit())
                ->keyBindings(['mod+s']),
            Action::make('saveDraft')
                ->label('Simpan Draft')
                ->color('gray')
                ->action(fn () => $this->create()),
            $this->getCancelFormAction(),
        ];
    }

    public function createAndSubmit(): void
    {
        $this->submitAfterCreate = true;
        $this->create();
    }

    protected function afterCreate(): void
    {
        if (! $this->submitAfterCreate || ! $this->record instanceof PurchaseRequest) {
            return;
        }

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        try {
            app(SubmitPurchaseRequestAction::class)->execute($this->record, $user);

            Notification::make()
                ->success()
                ->title('Purchase request berhasil dibuat dan diajukan.')
                ->send();
        } catch (DomainException $exception) {
            Notification::make()
                ->warning()
                ->title('Purchase request tersimpan sebagai draft.')
                ->body($exception->getMessage())
                ->send();
        }
    }

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
