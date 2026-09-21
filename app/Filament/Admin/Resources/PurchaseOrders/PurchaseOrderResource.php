<?php

namespace App\Filament\Admin\Resources\PurchaseOrders;

use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Fulfillment\ApprovePurchaseOrderExceptionCloseAction;
use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Fulfillment\RequestPurchaseOrderExceptionCloseAction;
use App\Actions\Procurement\AcknowledgePurchaseOrderAction;
use App\Actions\Procurement\ApprovePurchaseOrderAction;
use App\Actions\Procurement\IssuePurchaseOrderAction;
use App\Actions\Procurement\SubmitPurchaseOrderForApprovalAction;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Filament\Admin\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Support\ReferencePreviewModal;
use App\Models\DeliveryScheduleItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
use App\Services\Usability\WorkflowGuidanceService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationLabel = 'Purchase Order';

    protected static ?string $modelLabel = 'purchase order';

    protected static ?string $pluralModelLabel = 'purchase order';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan')
                ->schema([
                    TextEntry::make('number')->label('Nomor PO'),
                    TextEntry::make('supplier.display_name')->label('Supplier'),
                    TextEntry::make('kitchen.name')->label('SPPG'),
                    TextEntry::make('purchaseRequest.number')->label('Purchase Request')->placeholder('-'),
                    TextEntry::make('order_date')->label('Tanggal PO')->date('d/m/Y'),
                    TextEntry::make('revision_number')->label('Revisi')->numeric(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                        ->color(static fn ($state): string => static::statusColor($state)),
                    TextEntry::make('next_action')
                        ->label('Berikutnya')
                        ->state(fn (PurchaseOrder $record): string => app(WorkflowGuidanceService::class)->internalPurchaseOrder($record, auth()->user()))
                        ->icon('heroicon-o-arrow-right-circle')
                        ->columnSpan(2),
                    TextEntry::make('delivery_start')->label('Mulai pengiriman')->date('d/m/Y')->placeholder('-'),
                    TextEntry::make('delivery_end')->label('Batas pengiriman')->date('d/m/Y')->placeholder('-'),
                    TextEntry::make('notes')->label('Catatan')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Nilai Purchase Order')
                ->schema([
                    TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
                    TextEntry::make('tax_amount')->label('Pajak')->money('IDR'),
                    TextEntry::make('discount_amount')->label('Diskon')->money('IDR'),
                    TextEntry::make('total_amount')->label('Total PO')->money('IDR'),
                ])
                ->columns(4),
            Section::make('Item Purchase Order')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('product_name_snapshot')->label('Produk'),
                            TextEntry::make('ordered_qty')
                                ->label('Dipesan')
                                ->formatStateUsing(static fn ($state): string => static::formatQty((float) $state)),
                            TextEntry::make('unit_name_snapshot')->label('Satuan'),
                            TextEntry::make('unit_price')->label('Harga satuan')->money('IDR'),
                            TextEntry::make('subtotal')->label('Subtotal')->money('IDR'),
                            TextEntry::make('delivered_qty')
                                ->label('Dikirim')
                                ->formatStateUsing(static fn ($state): string => static::formatQty((float) $state)),
                            TextEntry::make('accepted_qty')
                                ->label('Diterima')
                                ->formatStateUsing(static fn ($state): string => static::formatQty((float) $state)),
                            TextEntry::make('rejected_qty')
                                ->label('Ditolak')
                                ->formatStateUsing(static fn ($state): string => static::formatQty((float) $state)),
                            TextEntry::make('description_snapshot')->label('Deskripsi')->placeholder('-')->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
            Section::make('Riwayat proses')
                ->schema([
                    TextEntry::make('creator.name')->label('Dibuat oleh')->placeholder('-'),
                    TextEntry::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i'),
                    TextEntry::make('approver.name')->label('Disetujui oleh')->placeholder('-'),
                    TextEntry::make('approved_at')->label('Disetujui')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('issued_at')->label('Diterbitkan')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('acknowledged_at')->label('Dikonfirmasi')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('paid_at')->label('Dibayar')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('closed_at')->label('Ditutup')->dateTime('d/m/Y H:i')->placeholder('-'),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor PO')->searchable()->sortable(),
                TextColumn::make('supplier.display_name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable()->sortable(),
                TextColumn::make('purchaseRequest.number')
                    ->label('PR')
                    ->searchable()
                    ->toggleable()
                    ->color('primary')
                    ->tooltip('Klik untuk preview purchase request')
                    ->action(ReferencePreviewModal::purchaseRequest(
                        'previewPurchaseRequest',
                        static fn (PurchaseOrder $record) => $record->purchaseRequest,
                    )),
                TextColumn::make('order_date')->label('Tanggal PO')->date('d/m/Y')->sortable(),
                TextColumn::make('delivery_start')->label('Mulai Kirim')->date('d/m/Y')->toggleable(),
                TextColumn::make('delivery_end')->label('Batas Kirim')->date('d/m/Y')->toggleable(),
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('total_amount')->label('Total')->money('IDR')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
                TextColumn::make('next_action')
                    ->label('Berikutnya')
                    ->state(fn (PurchaseOrder $record): string => app(WorkflowGuidanceService::class)->internalPurchaseOrder($record, auth()->user()))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->wrap(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('submitApproval')
                    ->label('Ajukan Approval')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft && static::hasScopedPermission($record, SystemPermission::PurchaseOrderCreate))
                    ->requiresConfirmation()
                    ->action(static function (PurchaseOrder $record): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderCreate);
                        static::runDomainAction(fn () => app(SubmitPurchaseOrderForApprovalAction::class)->execute($record, auth()->user()), 'PO berhasil diajukan untuk approval.');
                    }),
                Action::make('approve')
                    ->label('Setujui')
                    ->color('success')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::PendingApproval && static::hasScopedPermission($record, SystemPermission::PurchaseOrderApprove))
                    ->schema([
                        Textarea::make('comments')->label('Catatan approval')->rows(3),
                        Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderApprove);
                        static::runDomainAction(
                            fn () => app(ApprovePurchaseOrderAction::class)->execute($record, auth()->user(), $data['comments'] ?? null, $data['override_reason'] ?? null),
                            'Keputusan approval PO berhasil disimpan.',
                        );
                    }),
                Action::make('issue')
                    ->label('Terbitkan PO')
                    ->color('primary')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Approved && static::hasScopedPermission($record, SystemPermission::PurchaseOrderIssue))
                    ->requiresConfirmation()
                    ->action(static function (PurchaseOrder $record): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderIssue);
                        static::runDomainAction(fn () => app(IssuePurchaseOrderAction::class)->execute($record), 'PO berhasil diterbitkan dan siap dikonfirmasi supplier.');
                    }),
                Action::make('acknowledgeInternal')
                    ->label('Konfirmasi atas Nama Supplier')
                    ->color('warning')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Issued && static::hasScopedPermission($record, SystemPermission::PurchaseOrderAcknowledge))
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan konfirmasi internal')
                            ->rows(3)
                            ->helperText('Gunakan ketika supplier tidak memiliki akun portal atau konfirmasi diterima di luar sistem.'),
                    ])
                    ->requiresConfirmation()
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderAcknowledge);
                        static::runDomainAction(
                            fn () => app(AcknowledgePurchaseOrderAction::class)->execute($record, auth()->user(), $data['notes'] ?? 'Dikonfirmasi oleh admin atas nama supplier.'),
                            'PO berhasil dikonfirmasi secara internal.',
                        );
                    }),
                Action::make('schedule')
                    ->label('Jadwalkan Pengiriman')
                    ->color('warning')
                    ->visible(static fn (PurchaseOrder $record): bool => in_array($record->status, [
                        PurchaseOrderStatus::Acknowledged,
                        PurchaseOrderStatus::Scheduled,
                        PurchaseOrderStatus::PartiallyDelivered,
                    ], true) && static::hasScopedPermission($record, SystemPermission::DeliverySchedule))
                    ->schema(static fn (PurchaseOrder $record): array => [
                        DateTimePicker::make('planned_delivery_at')
                            ->label('Jadwal pengiriman')
                            ->native(false)
                            ->seconds(false)
                            ->required(),
                        Repeater::make('items')
                            ->label('Item yang dikirim')
                            ->schema([
                                Select::make('purchase_order_item_id')
                                    ->label('Item PO')
                                    ->options(static::schedulableItemOptions($record))
                                    ->searchable()
                                    ->required(),
                                TextInput::make('quantity')
                                    ->label('Jumlah')
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->required(),
                        Textarea::make('supplier_notes')->label('Catatan pengiriman')->rows(3),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::DeliverySchedule);

                        static::runDomainAction(function () use ($record, $data): void {
                            $items = [];

                            foreach ($data['items'] ?? [] as $row) {
                                $itemId = (int) ($row['purchase_order_item_id'] ?? 0);
                                if ($itemId <= 0 || isset($items[$itemId])) {
                                    throw new DomainException('Setiap item PO hanya boleh dipilih satu kali dalam satu jadwal.');
                                }

                                $items[$itemId] = (float) ($row['quantity'] ?? 0);
                            }

                            app(CreateDeliveryScheduleAction::class)->execute(
                                $record,
                                $items,
                                $data['planned_delivery_at'],
                                auth()->user(),
                                $data['supplier_notes'] ?? null,
                            );
                        }, 'Jadwal pengiriman berhasil dibuat.');
                    }),
                Action::make('createInvoiceInternal')
                    ->label('Buat Invoice Supplier')
                    ->color('success')
                    ->visible(static fn (PurchaseOrder $record): bool => in_array($record->status, [
                        PurchaseOrderStatus::Fulfilled,
                        PurchaseOrderStatus::ClosedWithException,
                    ], true) && static::canCreateInvoice($record) && ! $record->invoice()->exists())
                    ->schema([
                        TextInput::make('supplier_invoice_number')
                            ->label('Nomor invoice supplier')
                            ->maxLength(255)
                            ->helperText('Opsional untuk supplier yang belum mengirim invoice formal.'),
                        DatePicker::make('invoice_date')
                            ->label('Tanggal invoice')
                            ->native(false)
                            ->default(today())
                            ->required(),
                        TextInput::make('payment_term_days')
                            ->label('Termin pembayaran (hari)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        FileUpload::make('invoice_file')
                            ->label('File invoice')
                            ->disk(VendorFileStorage::DISK)
                            ->directory('invoices')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->helperText('Opsional. Invoice internal tetap dapat dibuat agar flow operasional tidak berhenti.'),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        abort_unless(static::canCreateInvoice($record), 403);
                        static::runDomainAction(
                            fn () => app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
                                $record,
                                auth()->user(),
                                $data['supplier_invoice_number'] ?? null,
                                $data['invoice_date'],
                                (int) ($data['payment_term_days'] ?? 0),
                                $data['invoice_file'] ?? null,
                            ),
                            'Draft invoice supplier berhasil dibuat.',
                        );
                    }),
                Action::make('requestExceptionClose')
                    ->label('Ajukan Close Exception')
                    ->color('danger')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::PartiallyDelivered && static::hasScopedPermission($record, SystemPermission::PurchaseOrderExceptionClose))
                    ->schema([
                        Textarea::make('reason')->label('Alasan penutupan dengan selisih')->required()->rows(4),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderExceptionClose);
                        static::runDomainAction(
                            fn () => app(RequestPurchaseOrderExceptionCloseAction::class)->execute($record, auth()->user(), $data['reason']),
                            'Permohonan close with exception berhasil diajukan.',
                        );
                    }),
                Action::make('approveExceptionClose')
                    ->label('Setujui Close Exception')
                    ->color('danger')
                    ->visible(static fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::PendingExceptionClosure && static::hasScopedPermission($record, SystemPermission::PurchaseOrderExceptionClose))
                    ->schema([
                        Textarea::make('resolution_notes')->label('Catatan penyelesaian discrepancy')->required()->rows(4),
                        Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                    ])
                    ->action(static function (PurchaseOrder $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::PurchaseOrderExceptionClose);
                        static::runDomainAction(
                            fn () => app(ApprovePurchaseOrderExceptionCloseAction::class)->execute(
                                $record,
                                auth()->user(),
                                $data['resolution_notes'],
                                $data['override_reason'] ?? null,
                            ),
                            'Close with exception berhasil diproses.',
                        );
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['supplier', 'kitchen', 'purchaseRequest'])
            ->withCount('items');
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            SystemPermission::PurchaseOrderCreate,
            SystemPermission::PurchaseOrderApprove,
            SystemPermission::PurchaseOrderIssue,
            SystemPermission::PurchaseOrderAcknowledge,
            SystemPermission::DeliverySchedule,
            SystemPermission::PurchaseOrderExceptionClose,
            SystemPermission::InvoiceSubmit,
            SystemPermission::InvoiceReview,
            SystemPermission::InvoiceApprove,
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

    private static function canCreateInvoice(PurchaseOrder $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->can(SystemPermission::InvoiceSubmit->value) || $user->can(SystemPermission::InvoiceReview->value))
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function hasScopedPermission(PurchaseOrder $record, SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function schedulableItemOptions(PurchaseOrder $record): array
    {
        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $record->getKey())
            ->with('unit')
            ->get()
            ->filter(function (PurchaseOrderItem $item): bool {
                return static::remainingSchedulableQty($item) > 0.0001;
            })
            ->mapWithKeys(static function (PurchaseOrderItem $item): array {
                $remaining = static::remainingSchedulableQty($item);
                $unit = $item->unit_name_snapshot ?: $item->unit?->symbol ?: $item->unit?->name;

                return [
                    $item->getKey() => sprintf('%s — sisa %s %s', $item->product_name_snapshot, static::formatQty($remaining), $unit),
                ];
            })
            ->all();
    }

    private static function remainingSchedulableQty(PurchaseOrderItem $item): float
    {
        $alreadyScheduled = (float) DeliveryScheduleItem::query()
            ->where('purchase_order_item_id', $item->getKey())
            ->whereHas('deliverySchedule', fn (Builder $query) => $query->where('status', '!=', DeliveryScheduleStatus::Cancelled->value))
            ->sum('planned_qty');

        return max(0, (float) $item->ordered_qty - $alreadyScheduled);
    }

    private static function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function requireScopedPermission(PurchaseOrder $record, SystemPermission $permission): void
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
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
            PurchaseOrderStatus::PendingApproval => 'Menunggu Approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Diterbitkan',
            PurchaseOrderStatus::Acknowledged => 'Dikonfirmasi',
            PurchaseOrderStatus::Scheduled => 'Terjadwal',
            PurchaseOrderStatus::PartiallyDelivered => 'Terkirim Sebagian',
            PurchaseOrderStatus::Fulfilled => 'Terpenuhi',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu Penutupan Exception',
            PurchaseOrderStatus::ClosedWithException => 'Ditutup dengan Exception',
            PurchaseOrderStatus::Invoiced => 'Ditagihkan',
            PurchaseOrderStatus::Paid => 'Dibayar',
            PurchaseOrderStatus::Closed => 'Selesai',
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Acknowledged,
            PurchaseOrderStatus::Fulfilled,
            PurchaseOrderStatus::Paid,
            PurchaseOrderStatus::Closed => 'success',
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Issued,
            PurchaseOrderStatus::Scheduled,
            PurchaseOrderStatus::PartiallyDelivered,
            PurchaseOrderStatus::PendingExceptionClosure,
            PurchaseOrderStatus::Invoiced => 'warning',
            PurchaseOrderStatus::Cancelled => 'danger',
            PurchaseOrderStatus::ClosedWithException => 'info',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseOrders::route('/'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
