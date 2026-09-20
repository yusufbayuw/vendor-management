<?php

namespace App\Filament\Admin\Resources\PurchaseRequests;

use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\CreatePurchaseRequestTemplateFromRequestAction;
use App\Actions\Procurement\DuplicatePurchaseRequestAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Actions\Procurement\RejectPurchaseRequestAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Admin\Support\MasterDataOptionFactory;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseRequestTemplate;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Access\UserAccessService;
use App\Services\Usability\WorkflowGuidanceService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
            Select::make('template_id')
                ->label('Gunakan template PR')
                ->helperText('Opsional. Memuat dapur, item, jumlah, spesifikasi, dan estimasi harga. Tanggal tetap diisi untuk PR baru.')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user) {
                        return [];
                    }

                    return app(UserAccessService::class)
                        ->applyKitchenOwnedScope(
                            PurchaseRequestTemplate::query()
                                ->where('is_active', true)
                                ->with('kitchen')
                                ->orderBy('name'),
                            $user,
                        )
                        ->get()
                        ->mapWithKeys(static fn (PurchaseRequestTemplate $template): array => [
                            $template->getKey() => $template->name.' — '.($template->kitchen?->name ?? 'SPPG'),
                        ])
                        ->all();
                })
                ->searchable()
                ->preload()
                ->dehydrated(false)
                ->visible(static fn (string $operation): bool => $operation === 'create')
                ->live()
                ->afterStateUpdated(static function (Set $set, mixed $state): void {
                    if (blank($state)) {
                        return;
                    }

                    $user = auth()->user();
                    $template = PurchaseRequestTemplate::query()
                        ->with('items')
                        ->find($state);

                    if (! $user || ! $template || ! app(UserAccessService::class)->canAccessKitchen($user, $template->sppg_kitchen_id)) {
                        return;
                    }

                    $set('sppg_kitchen_id', $template->sppg_kitchen_id);
                    $set('description', $template->description);
                    $set('notes', $template->notes);
                    $set('items', $template->items->map(static fn ($item): array => [
                        'product_id' => $item->product_id,
                        'unit_id' => $item->unit_id,
                        'requested_qty' => $item->requested_qty,
                        'estimated_unit_price' => $item->estimated_unit_price,
                        'description' => $item->description,
                        'quality_specification' => $item->quality_specification,
                        'notes' => $item->notes,
                        'preferred_delivery_date' => null,
                    ])->values()->all());
                }),
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
                    MasterDataOptionFactory::product(
                        Select::make('product_id')
                            ->label('Produk')
                            ->options(static fn (): array => Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                    )
                        ->live()
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            $unitId = filled($state)
                                ? Product::query()->whereKey($state)->value('default_unit_id')
                                : null;

                            $set('unit_id', $unitId);
                        })
                        ->required(),
                    MasterDataOptionFactory::unit(
                        Select::make('unit_id')
                            ->label('Satuan')
                            ->helperText('Otomatis mengikuti satuan default produk. Dapat diubah bila kebutuhan menggunakan satuan lain.')
                            ->options(static fn (): array => Unit::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ),
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan')
                ->schema([
                    TextEntry::make('number')->label('Nomor PR'),
                    TextEntry::make('kitchen.name')->label('SPPG'),
                    TextEntry::make('requester.name')->label('Pemohon'),
                    TextEntry::make('period_start')->label('Periode mulai')->date('d/m/Y'),
                    TextEntry::make('period_end')->label('Periode selesai')->date('d/m/Y'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                        ->color(static fn ($state): string => static::statusColor($state)),
                    TextEntry::make('next_action')
                        ->label('Berikutnya')
                        ->state(fn (PurchaseRequest $record): string => app(WorkflowGuidanceService::class)->purchaseRequest($record, auth()->user()))
                        ->icon('heroicon-o-arrow-right-circle')
                        ->columnSpanFull(),
                    TextEntry::make('description')->label('Deskripsi kebutuhan')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Item kebutuhan')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('product.name')->label('Produk'),
                            TextEntry::make('requested_qty')
                                ->label('Jumlah')
                                ->formatStateUsing(static fn ($state): string => rtrim(rtrim(number_format((float) $state, 4, ',', '.'), '0'), ',')),
                            TextEntry::make('unit.symbol')->label('Satuan')->placeholder('-'),
                            TextEntry::make('estimated_unit_price')->label('Estimasi harga')->money('IDR')->placeholder('-'),
                            TextEntry::make('preferred_delivery_date')->label('Tanggal kirim pilihan')->date('d/m/Y')->placeholder('-'),
                            TextEntry::make('quality_specification')->label('Spesifikasi kualitas')->placeholder('-')->columnSpanFull(),
                            TextEntry::make('description')->label('Deskripsi item')->placeholder('-')->columnSpanFull(),
                            TextEntry::make('notes')->label('Catatan item')->placeholder('-')->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ]),
            Section::make('Riwayat proses')
                ->schema([
                    TextEntry::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i'),
                    TextEntry::make('submitted_at')->label('Diajukan')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('approved_at')->label('Disetujui')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('approver.name')->label('Penyetuju')->placeholder('-'),
                    TextEntry::make('rejected_at')->label('Ditolak')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('rejection_reason')->label('Alasan penolakan')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(3),
        ]);
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
                ViewAction::make(),
                EditAction::make()->visible(static fn (PurchaseRequest $record): bool => static::canEdit($record)),
                Action::make('duplicateAsDraft')
                    ->label('Salin jadi Draft')
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(static fn (PurchaseRequest $record): bool => static::canReuse($record))
                    ->requiresConfirmation()
                    ->modalDescription('Item, jumlah, spesifikasi, dan estimasi harga akan disalin. Periode dan tanggal pengiriman sengaja dikosongkan agar tidak memakai tanggal lama.')
                    ->action(static function (PurchaseRequest $record) {
                        try {
                            $copy = app(DuplicatePurchaseRequestAction::class)->execute($record, auth()->user());

                            Notification::make()
                                ->success()
                                ->title('Draft PR baru berhasil dibuat.')
                                ->send();

                            return redirect()->to(static::getUrl('edit', ['record' => $copy]));
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return null;
                        }
                    }),
                Action::make('saveAsTemplate')
                    ->label('Simpan sebagai Template')
                    ->icon('heroicon-o-bookmark-square')
                    ->visible(static fn (PurchaseRequest $record): bool => static::canReuse($record))
                    ->schema([
                        TextInput::make('template_name')
                            ->label('Nama template')
                            ->default(static fn (PurchaseRequest $record): string => 'Template '.$record->number)
                            ->required()
                            ->maxLength(150),
                    ])
                    ->action(static function (PurchaseRequest $record, array $data): void {
                        try {
                            $template = app(CreatePurchaseRequestTemplateFromRequestAction::class)
                                ->execute($record, auth()->user(), (string) $data['template_name']);

                            Notification::make()
                                ->success()
                                ->title('Template PR berhasil dibuat.')
                                ->body($template->name)
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
                Action::make('submit')
                    ->label('Ajukan')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(static fn (PurchaseRequest $record): bool => $record->status === PurchaseRequestStatus::Draft && static::canSubmit($record))
                    ->action(static function (PurchaseRequest $record): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseRequestSubmit);
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
                        static::requireScopedPermission($record, SystemPermission::PurchaseRequestApprove);
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
                        static::requireScopedPermission($record, SystemPermission::PurchaseRequestApprove);
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
                        static::requireScopedPermission($record, SystemPermission::PurchaseRequestAllocate);

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
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderCreate);
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

    private static function canReuse(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can(SystemPermission::PurchaseRequestSubmit->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id)
            && ((int) ($record->getAttribute('items_count') ?? $record->items()->count())) > 0;
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

    private static function requireScopedPermission(PurchaseRequest $record, SystemPermission $permission): void
    {
        abort_unless(static::hasScopedPermission($record, $permission), 403);
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
            'view' => ViewPurchaseRequest::route('/{record}'),
            'edit' => EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
