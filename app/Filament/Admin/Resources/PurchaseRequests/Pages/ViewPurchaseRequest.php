<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\PurchaseRequests\RelationManagers\ItemsRelationManager;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePo')
                ->label('Generate PO')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $this->record->status === PurchaseRequestStatus::FullyAllocated
                        && $user !== null
                        && $user->can(SystemPermission::PurchaseOrderCreate->value)
                        && app(UserAccessService::class)->canAccessKitchen($user, $this->record->sppg_kitchen_id);
                })
                ->action(function (): void {
                    $user = auth()->user();

                    abort_unless(
                        $this->record->status === PurchaseRequestStatus::FullyAllocated
                        && $user !== null
                        && $user->can(SystemPermission::PurchaseOrderCreate->value)
                        && app(UserAccessService::class)->canAccessKitchen($user, $this->record->sppg_kitchen_id),
                        403,
                    );

                    try {
                        app(GeneratePurchaseOrdersAction::class)->execute($this->record, $user);
                        $this->record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Purchase order berhasil dibuat per supplier.')
                            ->send();
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->danger()
                            ->title($exception->getMessage())
                            ->send();
                    }
                }),
        ];
    }

    protected function getAllRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
