<?php

namespace App\Filament\Supplier\Resources\PurchaseOrders;

use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Procurement\AcknowledgePurchaseOrderAction;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Models\DeliveryScheduleItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Usability\WorkflowGuidanceService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
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

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationLabel = 'Purchase Order';

    protected static string|UnitEnum|null $navigationGroup = 'Transaksi';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Nomor PO')->searchable()->sortable(),
            TextColumn::make('kitchen.name')->label('SPPG')->searchable(),
            TextColumn::make('order_date')->label('Tanggal')->date('d/m/Y')->sortable(),
            TextColumn::make('delivery_start')->label('Mulai Kirim')->date('d/m/Y'),
            TextColumn::make('delivery_end')->label('Batas Kirim')->date('d/m/Y'),
            TextColumn::make('items_count')->label('Item'),
            TextColumn::make('total_amount')->label('Total')->money('IDR'),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof PurchaseOrderStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
            TextColumn::make('next_action')
                ->label('Berikutnya')
                ->state(fn (PurchaseOrder $record): string => app(WorkflowGuidanceService::class)->supplierPurchaseOrder($record, auth()->user()))
                ->icon('heroicon-o-arrow-right-circle')
                ->wrap(),
        ])->recordActions([
            Action::make('acknowledge')->label('Konfirmasi PO')->color('success')->requiresConfirmation()
                ->visible(fn (PurchaseOrder $record) => $record->status === PurchaseOrderStatus::Issued && static::allowed($record, SystemPermission::PurchaseOrderAcknowledge))
                ->schema([Textarea::make('notes')->label('Catatan')->rows(3)])
                ->action(function (PurchaseOrder $record, array $data): void {
                    static::run(fn () => app(AcknowledgePurchaseOrderAction::class)->execute($record, auth()->user(), $data['notes'] ?? null), 'PO berhasil dikonfirmasi.');
                }),
            Action::make('schedule')->label('Buat Jadwal Kirim')->color('warning')
                ->visible(fn (PurchaseOrder $record) => in_array($record->status, [PurchaseOrderStatus::Acknowledged, PurchaseOrderStatus::Scheduled, PurchaseOrderStatus::PartiallyDelivered], true) && static::allowed($record, SystemPermission::DeliveryManage))
                ->schema(fn (PurchaseOrder $record): array => [
                    DateTimePicker::make('planned_delivery_at')->label('Jadwal')->native(false)->seconds(false)->required(),
                    Repeater::make('items')->label('Item')
                        ->schema([
                            Select::make('item_id')->label('Item PO')->options(static::itemOptions($record))->searchable()->required(),
                            TextInput::make('qty')->label('Jumlah')->numeric()->minValue(0.0001)->required(),
                        ])->columns(2)->minItems(1)->defaultItems(1)->required(),
                    Textarea::make('notes')->label('Catatan')->rows(3),
                ])->action(function (PurchaseOrder $record, array $data): void {
                    static::run(function () use ($record, $data): void {
                        $items = [];
                        foreach ($data['items'] ?? [] as $row) {
                            $id = (int) ($row['item_id'] ?? 0);
                            if ($id <= 0 || isset($items[$id])) {
                                throw new DomainException('Item PO duplikat atau tidak valid.');
                            }
                            $items[$id] = (float) ($row['qty'] ?? 0);
                        }
                        app(CreateDeliveryScheduleAction::class)->execute($record, $items, $data['planned_delivery_at'], auth()->user(), $data['notes'] ?? null);
                    }, 'Jadwal pengiriman berhasil dibuat.');
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with('kitchen')->withCount('items');
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('supplier_id', static::supplierIds())
            ->whereNotIn('status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::PendingApproval->value, PurchaseOrderStatus::Approved->value]);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->can(SystemPermission::PurchaseOrderAcknowledge->value) || $user->can(SystemPermission::DeliveryManage->value));
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

    private static function allowed(PurchaseOrder $po, SystemPermission $permission): bool
    {
        return auth()->user()?->can($permission->value)
            && in_array($po->supplier_id, static::supplierIds(), true);
    }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function itemOptions(PurchaseOrder $po): array
    {
        return PurchaseOrderItem::query()->where('purchase_order_id', $po->getKey())->get()
            ->filter(fn (PurchaseOrderItem $item) => static::remaining($item) > 0.0001)
            ->mapWithKeys(fn (PurchaseOrderItem $item) => [
                $item->getKey() => sprintf('%s — sisa %s %s', $item->product_name_snapshot, static::qty(static::remaining($item)), $item->unit_name_snapshot),
            ])->all();
    }

    private static function remaining(PurchaseOrderItem $item): float
    {
        $scheduled = (float) DeliveryScheduleItem::query()
            ->where('purchase_order_item_id', $item->getKey())
            ->whereHas('deliverySchedule', fn (Builder $q) => $q->where('status', '!=', DeliveryScheduleStatus::Cancelled->value))
            ->sum('planned_qty');

        return max(0, (float) $item->ordered_qty - $scheduled);
    }

    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
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
        return ['index' => ManagePurchaseOrders::route('/')];
    }
}
