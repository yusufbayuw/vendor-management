<?php

namespace App\Filament\Supplier\Resources\DeliverySchedules;

use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\MarkDeliveryInTransitAction;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\DeliverySchedules\Pages\ManageDeliverySchedules;
use App\Filament\Support\SecureFileModal;
use App\Models\DeliverySchedule;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DeliveryScheduleResource extends Resource
{
    protected static ?string $model = DeliverySchedule::class;

    protected static ?string $navigationLabel = 'Pengiriman';

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Nomor')->searchable()->sortable(),
            TextColumn::make('purchaseOrder.number')->label('PO')->searchable(),
            TextColumn::make('purchaseOrder.kitchen.name')->label('SPPG')->searchable(),
            TextColumn::make('planned_delivery_at')->label('Jadwal')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('estimated_arrival_at')->label('Estimasi Tiba')->dateTime('d/m/Y H:i'),
            TextColumn::make('driver_name')->label('Pengemudi')->toggleable(),
            TextColumn::make('vehicle_number')->label('Kendaraan')->toggleable(),
            TextColumn::make('delivery_note_file')
                ->label('Surat Jalan')
                ->icon('heroicon-o-paper-clip')
                ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : '-')
                ->action(SecureFileModal::make(
                    'previewDeliveryNote',
                    fn (DeliverySchedule $record): string => route('files.delivery-notes.show', $record),
                    fn (DeliverySchedule $record): string => route('files.delivery-notes.show', [
                        'deliverySchedule' => $record,
                        'download' => 1,
                    ]),
                    fn (DeliverySchedule $record): ?string => $record->delivery_note_file,
                )),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof DeliveryScheduleStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
        ])->recordActions([
            Action::make('confirm')->label('Konfirmasi')->color('success')->requiresConfirmation()
                ->visible(fn (DeliverySchedule $record) => in_array($record->status, [DeliveryScheduleStatus::Draft, DeliveryScheduleStatus::Planned], true) && static::allowed($record))
                ->action(fn (DeliverySchedule $record) => static::run(
                    fn () => app(ConfirmDeliveryScheduleAction::class)->execute($record, auth()->user()),
                    'Jadwal dikonfirmasi.',
                )),
            Action::make('depart')->label('Berangkat')->color('primary')
                ->visible(fn (DeliverySchedule $record) => $record->status === DeliveryScheduleStatus::Confirmed && static::allowed($record))
                ->schema([
                    TextInput::make('driver_name')->label('Nama pengemudi')->required(),
                    TextInput::make('driver_phone')->label('No. HP')->tel(),
                    TextInput::make('vehicle_number')->label('Nomor kendaraan')->required(),
                    DateTimePicker::make('estimated_arrival_at')->label('Estimasi tiba')->native(false)->seconds(false),
                    TextInput::make('delivery_note_number')->label('Nomor surat jalan'),
                    FileUpload::make('delivery_note_file')
                        ->label('Surat jalan')
                        ->disk(VendorFileStorage::DISK)
                        ->directory('delivery-notes')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240),
                ])->action(fn (DeliverySchedule $record, array $data) => static::run(
                    fn () => app(MarkDeliveryInTransitAction::class)->execute($record, $data),
                    'Pengiriman diberangkatkan.',
                )),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $supplierIds = static::supplierIds();

        return parent::getEloquentQuery()->with('purchaseOrder.kitchen')
            ->whereHas('purchaseOrder', fn (Builder $q) => $q->whereIn('supplier_id', $supplierIds));
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::DeliveryManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function allowed(DeliverySchedule $schedule): bool
    {
        $schedule->loadMissing('purchaseOrder');

        return auth()->user()?->can(SystemPermission::DeliveryManage->value)
            && in_array($schedule->purchaseOrder->supplier_id, static::supplierIds(), true);
    }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function run(callable $callback, string $message): void
    {
        try {
            $callback();
            Notification::make()->success()->title($message)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeliverySchedules::route('/')];
    }
}
