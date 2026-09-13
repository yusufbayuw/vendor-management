<?php

namespace App\Filament\Admin\Resources\DeliverySchedules;

use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\MarkDeliveryInTransitAction;
use App\Actions\Fulfillment\RecordGoodsReceiptAction;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\DeliverySchedules\Pages\ManageDeliverySchedules;
use App\Filament\Support\SecureFileModal;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleItem;
use App\Models\GoodsReceiptItem;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

    protected static ?string $navigationLabel = 'Jadwal Pengiriman';

    protected static ?string $modelLabel = 'jadwal pengiriman';

    protected static ?string $pluralModelLabel = 'jadwal pengiriman';

    protected static string|UnitEnum|null $navigationGroup = 'Fulfillment';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor Jadwal')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.supplier.display_name')->label('Supplier')->searchable(),
                TextColumn::make('purchaseOrder.kitchen.name')->label('SPPG')->searchable(),
                TextColumn::make('planned_delivery_at')->label('Jadwal')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('estimated_arrival_at')->label('Estimasi Tiba')->dateTime('d/m/Y H:i')->toggleable(),
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
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Konfirmasi Jadwal')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(static fn (DeliverySchedule $record): bool => in_array($record->status, [DeliveryScheduleStatus::Draft, DeliveryScheduleStatus::Planned], true) && static::hasScopedPermission($record, SystemPermission::DeliverySchedule))
                    ->action(static function (DeliverySchedule $record): void {
                        static::requirePermission(SystemPermission::DeliverySchedule);
                        static::runDomainAction(
                            fn () => app(ConfirmDeliveryScheduleAction::class)->execute($record, auth()->user()),
                            'Jadwal pengiriman berhasil dikonfirmasi.',
                        );
                    }),
                Action::make('inTransit')
                    ->label('Berangkat')
                    ->color('primary')
                    ->visible(static fn (DeliverySchedule $record): bool => $record->status === DeliveryScheduleStatus::Confirmed && static::hasScopedPermission($record, SystemPermission::DeliveryManage))
                    ->schema([
                        TextInput::make('driver_name')->label('Nama pengemudi')->maxLength(255),
                        TextInput::make('driver_phone')->label('No. HP pengemudi')->tel()->maxLength(50),
                        TextInput::make('vehicle_number')->label('Nomor kendaraan')->maxLength(50),
                        DateTimePicker::make('estimated_arrival_at')->label('Estimasi tiba')->native(false)->seconds(false),
                        TextInput::make('delivery_note_number')->label('Nomor surat jalan')->maxLength(100),
                        FileUpload::make('delivery_note_file')
                            ->label('File surat jalan')
                            ->disk(VendorFileStorage::DISK)
                            ->directory('delivery-notes')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240),
                    ])
                    ->action(static function (DeliverySchedule $record, array $data): void {
                        static::requirePermission(SystemPermission::DeliveryManage);
                        static::runDomainAction(
                            fn () => app(MarkDeliveryInTransitAction::class)->execute($record, $data),
                            'Pengiriman ditandai sedang dalam perjalanan.',
                        );
                    }),
                Action::make('receive')
                    ->label('Terima Barang')
                    ->color('warning')
                    ->visible(static fn (DeliverySchedule $record): bool => in_array($record->status, [
                        DeliveryScheduleStatus::Confirmed,
                        DeliveryScheduleStatus::InTransit,
                        DeliveryScheduleStatus::Arrived,
                        DeliveryScheduleStatus::PartiallyReceived,
                    ], true) && static::hasScopedPermission($record, SystemPermission::GoodsReceiptCreate))
                    ->schema(static fn (DeliverySchedule $record): array => [
                        Repeater::make('items')
                            ->label('Barang diterima')
                            ->schema([
                                Select::make('delivery_schedule_item_id')
                                    ->label('Item')
                                    ->options(static::receivableItemOptions($record))
                                    ->searchable()
                                    ->required(),
                                TextInput::make('received_qty')
                                    ->label('Jumlah diterima fisik')
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->required(),
                        TextInput::make('supplier_representative')->label('Perwakilan supplier')->maxLength(255),
                        Textarea::make('notes')->label('Catatan penerimaan')->rows(3),
                    ])
                    ->action(static function (DeliverySchedule $record, array $data): void {
                        static::requirePermission(SystemPermission::GoodsReceiptCreate);

                        static::runDomainAction(function () use ($record, $data): void {
                            $items = [];

                            foreach ($data['items'] ?? [] as $row) {
                                $itemId = (int) ($row['delivery_schedule_item_id'] ?? 0);
                                if ($itemId <= 0 || isset($items[$itemId])) {
                                    throw new DomainException('Setiap item jadwal hanya boleh dipilih satu kali dalam satu penerimaan.');
                                }

                                $items[$itemId] = (float) ($row['received_qty'] ?? 0);
                            }

                            app(RecordGoodsReceiptAction::class)->execute(
                                $record,
                                $items,
                                auth()->user(),
                                $data['supplier_representative'] ?? null,
                                $data['notes'] ?? null,
                            );
                        }, 'Penerimaan barang berhasil dicatat dan menunggu QC.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['purchaseOrder.supplier', 'purchaseOrder.kitchen'])
            ->withCount('items');
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (app(UserAccessService::class)->hasGlobalAccess($user)) {
            return $query;
        }

        return $query->whereHas('purchaseOrder', function (Builder $purchaseOrderQuery) use ($user): void {
            app(UserAccessService::class)->applyKitchenOwnedScope($purchaseOrderQuery, $user);
        });
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            SystemPermission::DeliverySchedule,
            SystemPermission::DeliveryManage,
            SystemPermission::GoodsReceiptCreate,
            SystemPermission::GoodsReceiptInspect,
        ])->contains(static fn (SystemPermission $permission): bool => $user->can($permission->value));
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

    private static function hasScopedPermission(DeliverySchedule $record, SystemPermission $permission): bool
    {
        $user = auth()->user();
        $record->loadMissing('purchaseOrder');

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->purchaseOrder->sppg_kitchen_id);
    }

    private static function receivableItemOptions(DeliverySchedule $record): array
    {
        return DeliveryScheduleItem::query()
            ->where('delivery_schedule_id', $record->getKey())
            ->with(['purchaseOrderItem', 'unit'])
            ->get()
            ->filter(static fn (DeliveryScheduleItem $item): bool => static::remainingReceivableQty($item) > 0.0001)
            ->mapWithKeys(static function (DeliveryScheduleItem $item): array {
                $remaining = static::remainingReceivableQty($item);
                $name = $item->purchaseOrderItem?->product_name_snapshot ?? 'Item';
                $unit = $item->purchaseOrderItem?->unit_name_snapshot ?: $item->unit?->symbol ?: $item->unit?->name;

                return [
                    $item->getKey() => sprintf('%s — sisa %s %s', $name, static::formatQty($remaining), $unit),
                ];
            })
            ->all();
    }

    private static function remainingReceivableQty(DeliveryScheduleItem $item): float
    {
        $alreadyReceived = (float) GoodsReceiptItem::query()
            ->where('delivery_schedule_item_id', $item->getKey())
            ->whereHas('goodsReceipt', fn (Builder $query) => $query->where('status', '!=', GoodsReceiptStatus::Cancelled->value))
            ->sum('received_qty');

        return max(0, (float) $item->planned_qty - $alreadyReceived);
    }

    private static function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function requirePermission(SystemPermission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }

    private static function runDomainAction(callable $action, string $successMessage): void
    {
        try {
            $action();
            Notification::make()->success()->title($successMessage)->send();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof DeliveryScheduleStatus ? $state : DeliveryScheduleStatus::tryFrom((string) $state);

        return match ($status) {
            DeliveryScheduleStatus::Draft => 'Draft',
            DeliveryScheduleStatus::Planned => 'Direncanakan',
            DeliveryScheduleStatus::Confirmed => 'Dikonfirmasi',
            DeliveryScheduleStatus::InTransit => 'Dalam Perjalanan',
            DeliveryScheduleStatus::Arrived => 'Tiba',
            DeliveryScheduleStatus::PartiallyReceived => 'Diterima Sebagian',
            DeliveryScheduleStatus::Received => 'Diterima',
            DeliveryScheduleStatus::Missed => 'Terlewat',
            DeliveryScheduleStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof DeliveryScheduleStatus ? $state : DeliveryScheduleStatus::tryFrom((string) $state);

        return match ($status) {
            DeliveryScheduleStatus::Confirmed,
            DeliveryScheduleStatus::Received => 'success',
            DeliveryScheduleStatus::Planned,
            DeliveryScheduleStatus::InTransit,
            DeliveryScheduleStatus::Arrived,
            DeliveryScheduleStatus::PartiallyReceived => 'warning',
            DeliveryScheduleStatus::Missed,
            DeliveryScheduleStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDeliverySchedules::route('/'),
        ];
    }
}
