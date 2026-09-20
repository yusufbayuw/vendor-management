<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\CreatePurchaseRequestTemplateFromRequestAction;
use App\Actions\Procurement\DuplicatePurchaseRequestAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Actions\Procurement\RejectPurchaseRequestAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Admin\Resources\PurchaseRequests\RelationManagers\ItemsRelationManager;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Edit Draft')
                ->visible(fn (): bool => PurchaseRequestResource::canEdit($this->record)),
            Action::make('submit')
                ->label('Ajukan PR')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === PurchaseRequestStatus::Draft
                    && $this->canForRecord(SystemPermission::PurchaseRequestSubmit))
                ->action(function (): void {
                    try {
                        app(SubmitPurchaseRequestAction::class)->execute($this->record, auth()->user());
                        $this->record->refresh();

                        Notification::make()->success()->title('Purchase request berhasil diajukan.')->send();
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            Action::make('approve')
                ->label('Setujui PR')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseRequestStatus::Submitted,
                    PurchaseRequestStatus::UnderReview,
                ], true) && $this->canForRecord(SystemPermission::PurchaseRequestApprove))
                ->schema([
                    Textarea::make('comments')->label('Catatan approval')->rows(3),
                    Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ApprovePurchaseRequestAction::class)->execute(
                            $this->record,
                            auth()->user(),
                            $data['comments'] ?? null,
                            $data['override_reason'] ?? null,
                        );
                        $this->record->refresh();

                        Notification::make()->success()->title('Purchase request disetujui.')->send();
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            Action::make('reject')
                ->label('Tolak PR')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [
                    PurchaseRequestStatus::Submitted,
                    PurchaseRequestStatus::UnderReview,
                ], true) && $this->canForRecord(SystemPermission::PurchaseRequestApprove))
                ->schema([
                    Textarea::make('reason')->label('Alasan penolakan')->required()->rows(4),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RejectPurchaseRequestAction::class)->execute(
                            $this->record,
                            auth()->user(),
                            (string) $data['reason'],
                        );
                        $this->record->refresh();

                        Notification::make()->success()->title('Purchase request ditolak.')->send();
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            Action::make('duplicateAsDraft')
                ->label('Salin jadi Draft')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => $this->canReuse())
                ->requiresConfirmation()
                ->modalDescription('Item, jumlah, spesifikasi, dan estimasi harga akan disalin. Periode dan tanggal pengiriman dikosongkan.')
                ->action(function () {
                    try {
                        $copy = app(DuplicatePurchaseRequestAction::class)->execute($this->record, auth()->user());

                        Notification::make()->success()->title('Draft PR baru berhasil dibuat.')->send();

                        return redirect()->to(PurchaseRequestResource::getUrl('edit', ['record' => $copy]));
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return null;
                    }
                }),
            Action::make('saveAsTemplate')
                ->label('Simpan Template')
                ->icon('heroicon-o-bookmark-square')
                ->visible(fn (): bool => $this->canReuse())
                ->schema([
                    TextInput::make('template_name')
                        ->label('Nama template')
                        ->default(fn (): string => 'Template '.$this->record->number)
                        ->required()
                        ->maxLength(150),
                ])
                ->action(function (array $data): void {
                    try {
                        $template = app(CreatePurchaseRequestTemplateFromRequestAction::class)
                            ->execute($this->record, auth()->user(), (string) $data['template_name']);

                        Notification::make()
                            ->success()
                            ->title('Template PR berhasil dibuat.')
                            ->body($template->name)
                            ->send();
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
            Action::make('generatePo')
                ->label('Generate PO')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $this->record->status === PurchaseRequestStatus::FullyAllocated
                        && $this->canForRecord(SystemPermission::PurchaseOrderCreate);
                })
                ->action(function (): void {
                    $user = auth()->user();

                    abort_unless(
                        $this->record->status === PurchaseRequestStatus::FullyAllocated
                        && $this->canForRecord(SystemPermission::PurchaseOrderCreate),
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

    private function canForRecord(SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $this->record->sppg_kitchen_id);
    }

    private function canReuse(): bool
    {
        return $this->canForRecord(SystemPermission::PurchaseRequestSubmit)
            && $this->record->items()->exists();
    }

    protected function getAllRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
