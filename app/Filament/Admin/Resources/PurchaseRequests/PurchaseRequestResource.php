<?php

namespace App\Filament\Admin\Resources\PurchaseRequests;

use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Actions\Procurement\RejectPurchaseRequestAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Access\UserAccessService;
use App\Services\Usability\WorkflowGuidanceService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationLabel = 'Purchase Request';

    protected static ?string $modelLabel = 'purchase request';

    protected static ?string $pluralModelLabel = 'purchase request';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('sppg_kitchen_id')
                ->label('Dapur SPPG')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user) {
                        return [];
                    }

                    return app(UserAccessService::class)
                        ->applyKitchenScope(SppgKitchen::query()->where('is_active', true)->orderBy('name'), $user)
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->required(),
            DatePicker::make('period_start')->label('Periode mulai')->native(false),
            DatePicker::make('period_end')->label('Periode selesai')->native(false)->afterOrEqual('period_start'),
            DatePicker::make('needed_from')->label('Kebutuhan mulai')->native(false),
            DatePicker::make('needed_until')->label('Kebutuhan sampai')->native(false)->afterOrEqual('needed_from'),
            Textarea::make('description')->label('Deskripsi kebutuhan')->rows(3)->columnSpanFull(),
            Textarea::make('notes')->label('Catatan')->rows(3)->columnSpanFull(),
            Repeater::make('items')
                ->label('Item Kebutuhan')
                ->relationship()
                ->schema([
                    Select::make('product_id')
                        ->label('Produk')
                        ->options(Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            $unitId = filled($state)
                                ? Product::query()->whereKey($state)->value('default_unit_id')
                                : null;

                            $set('unit_id', $unitId);
                        })
                        ->required(),
                    Select::make('unit_id')
                        ->label('Satuan')
                        ->helperText('Otomatis mengikuti satuan default produk. Dapat diubah bila kebutuhan menggunakan satuan lain.')
                        ->options(Unit::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('requested_qty')->label('Jumlah')->numeric()->minValue(0.0001)->required(),
                    TextInput::make('estimated_unit_price')->label('Estimasi harga satuan')->numeric()->prefix('Rp')->minValue(0),
                    DatePicker::make('preferred_delivery_date')->label('Tanggal pengiriman pilihan')->native(false),
                    TextInput::make('description')->label('Deskripsi item')->maxLength(255),
                    Textarea::make('quality_specification')->label('Spesifikasi kualitas')->rows(2)->columnSpanFull(),
                    Textarea::make('notes')->label('Catatan item')->rows(2)->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->reorderable()
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor PR')->searchable()->sortable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable()->sortable(),
                TextColumn::make('requester.name')->label('Pemohon')->toggleable(),
                TextColumn::make('period_start')->label('Mulai')->date('d/m/Y')->sortable(),
                TextColumn::make('period_end')->label('Selesai')->date('d/m/Y')->sortable(),
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
                TextColumn::make('next_action')
                    ->label('Berikutnya')
                    ->state(fn (PurchaseRequest $record): string => app(WorkflowGuidanceService::class)->purchaseRequest($record, auth()->user()))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->wrap(),
            ])
            ->recordActions([
                EditAction::make()->visible(static fn (PurchaseRequest $record): bool => static::canEdit($record)),
                Action::make('submit')
                    ->label('Ajukan')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(static fn (PurchaseRequest $record): bool => $record->status === PurchaseRequestStatus::Draft && static::canSubmit($record))
                    ->action(static function (PurchaseRequest $record): void {
                        static::requirePermission(SystemPermission::PurchaseRequestSubmit);
                        static::runDomainAction(fn () => app(SubmitPurchaseRequestAction::class)->execute($record, auth()->user()), 'Purchase request berhasil diajukan.');
                    }),
                Action::make('approve')
                    ->label('Setujui')
                    ->color('success')
                    ->visible(static fn (PurchaseRequest $record): bool => in_array($record->status, [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview], true) && static::canApprove($record))
                    ->schema([
                        Textarea::make('comments')->label('Catatan approval')->rows(3),
                        Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                    ])
                    ->action(static function (PurchaseRequest $record, array $data): void {
                        static::requirePermission(SystemPermission::PurchaseRequestApprove);
                        static::runDomainAction(
                            fn () => app(ApprovePurchaseRequestAction::class)->execute($record, auth()->user(), $data['comments'] ?? null, $data['override_reason'] ?? null),
                            'Keputusan approval berhasil disimpan.',
                        );
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->color('danger')
                    ->visible(static fn (PurchaseRequest $record): bool => in_array($record->status, [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview], true) && static::canApprove($record))
                    ->schema([
                        Textarea::make('reason')->label('Alasan penolakan')->required()->rows(4),
                    ])
                    ->action(static function (PurchaseRequest $record, array $data): void {
                        static::requirePermission(SystemPermission::PurchaseRequestApprove);
                        static::runDomainAction(fn () => app(RejectPurchaseRequestAction::class)->execute($record, auth()->user(), $data['reason']), 'Purchase request ditolak.');
                    }),
                Action::make('allocate')
                    ->label('Alokasikan Supplier')
                    ->color('warning')
                    ->visible(static fn (PurchaseRequest $record): bool => in_array($record->status, [PurchaseRequestStatus::Approved, PurchaseRequestStatus::PartiallyAllocated], true) && static::canAllocate($record))
                    ->schema(static fn (PurchaseRequest $record): array => [
                        Select::make('item_id')
                            ->label('Item PR')
                            ->options(static::allocatableItemOptions($record))
                            ->searchable()
                            ->required(),
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(Supplier::query()
                                ->where('status', SupplierStatus::Active->value)
                                ->orderBy('display_name')
                                ->pluck('display_name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('quantity')->label('Jumlah alokasi')->numeric()->minValue(0.0001)->required(),
                        TextInput::make('unit_price')->label('Harga satuan')->numeric()->prefix('Rp')->minValue(0)->required(),
                        Textarea::make('notes')->label('Catatan alokasi')->rows(3),
                    ])
                    ->action(static function (PurchaseRequest $record, array $data): void {
                        static::requirePermission(SystemPermission::PurchaseRequestAllocate);

                        $item = PurchaseRequestItem::query()
                            ->where('purchase_request_id', $record->getKey())
                            ->findOrFail($data['item_id']);
                        $supplier = Supplier::query()->findOrFail($data['supplier_id']);

                        static::runDomainAction(
                            fn () => app(AllocatePurchaseRequestItemAction::class)->execute(
                                $item,
                                $supplier,
                                (float) $data['quantity'],
                                (float) $data['unit_price'],
                                auth()->user(),
                                $data['notes'] ?? null,
                            ),
                            'Alokasi supplier berhasil disimpan.',
                        );
                    }),
                Action::make('generatePo')
                    ->label('Generate PO')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(static fn (PurchaseRequest $record): bool => $record->status === PurchaseRequestStatus::FullyAllocated && static::canGeneratePo($record))
                    ->action(static function (PurchaseRequest $record): void {
                        static::requirePermission(SystemPermission::PurchaseOrderCreate);
                        static::runDomainAction(fn () => app(GeneratePurchaseOrdersAction::class)->execute($record, auth()->user()), 'Purchase order berhasil dibuat per supplier.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['kitchen', 'requester'])->withCount('items');
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can(SystemPermission::PurchaseRequestSubmit->value)
            || $user->can(SystemPermission::PurchaseRequestApprove->value)
            || $user->can(SystemPermission::PurchaseRequestAllocate->value)
            || $user->can(SystemPermission::PurchaseOrderCreate->value)
        );
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(SystemPermission::PurchaseRequestSubmit->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! $record instanceof PurchaseRequest || $record->status !== PurchaseRequestStatus::Draft) {
            return false;
        }

        $user = auth()->user();

        return $user !== null
            && $user->can(SystemPermission::PurchaseRequestSubmit->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function canSubmit(PurchaseRequest $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseRequestSubmit);
    }

    private static function canApprove(PurchaseRequest $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseRequestApprove);
    }

    private static function canAllocate(PurchaseRequest $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseRequestAllocate);
    }

    private static function canGeneratePo(PurchaseRequest $record): bool
    {
        return static::hasScopedPermission($record, SystemPermission::PurchaseOrderCreate);
    }

    private static function hasScopedPermission(PurchaseRequest $record, SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function allocatableItemOptions(PurchaseRequest $record): array
    {
        return PurchaseRequestItem::query()
            ->where('purchase_request_id', $record->getKey())
            ->with(['product', 'unit'])
            ->withSum('allocations as allocated_qty_sum', 'allocated_qty')
            ->get()
            ->filter(static fn (PurchaseRequestItem $item): bool => ((float) $item->requested_qty - (float) ($item->allocated_qty_sum ?? 0)) > 0.0001)
            ->mapWithKeys(static function (PurchaseRequestItem $item): array {
                $remaining = max(0, (float) $item->requested_qty - (float) ($item->allocated_qty_sum ?? 0));
                $unit = $item->unit?->symbol ?: $item->unit?->name;

                return [
                    $item->getKey() => sprintf('%s — sisa %s %s', $item->product?->name ?? 'Item', rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.'), $unit),
                ];
            })
            ->all();
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
        $status = $state instanceof PurchaseRequestStatus ? $state : PurchaseRequestStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseRequestStatus::Draft => 'Draft',
            PurchaseRequestStatus::Submitted => 'Diajukan',
            PurchaseRequestStatus::UnderReview => 'Dalam Approval',
            PurchaseRequestStatus::Approved => 'Disetujui',
            PurchaseRequestStatus::PartiallyAllocated => 'Alokasi Sebagian',
            PurchaseRequestStatus::FullyAllocated => 'Alokasi Penuh',
            PurchaseRequestStatus::PoGenerated => 'PO Dibuat',
            PurchaseRequestStatus::Closed => 'Selesai',
            PurchaseRequestStatus::Cancelled => 'Dibatalkan',
            PurchaseRequestStatus::Rejected => 'Ditolak',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof PurchaseRequestStatus ? $state : PurchaseRequestStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseRequestStatus::Approved, PurchaseRequestStatus::FullyAllocated, PurchaseRequestStatus::PoGenerated, PurchaseRequestStatus::Closed => 'success',
            PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview, PurchaseRequestStatus::PartiallyAllocated => 'warning',
            PurchaseRequestStatus::Rejected, PurchaseRequestStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequests::route('/'),
            'create' => CreatePurchaseRequest::route('/create'),
            'edit' => EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
