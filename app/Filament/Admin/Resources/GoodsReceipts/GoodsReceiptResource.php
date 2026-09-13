<?php

namespace App\Filament\Admin\Resources\GoodsReceipts;

use App\Actions\Fulfillment\AddGoodsReceiptAttachmentAction;
use App\Actions\Fulfillment\InspectGoodsReceiptAction;
use App\Enums\GoodsReceiptAttachmentType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\GoodsReceipts\Pages\ManageGoodsReceipts;
use App\Filament\Support\SecureFileGalleryModal;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptAttachment;
use App\Models\GoodsReceiptItem;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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

class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static ?string $navigationLabel = 'Penerimaan Barang';

    protected static ?string $modelLabel = 'penerimaan barang';

    protected static ?string $pluralModelLabel = 'penerimaan barang';

    protected static string|UnitEnum|null $navigationGroup = 'Fulfillment';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor GR')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable()->sortable(),
                TextColumn::make('deliverySchedule.number')->label('Jadwal')->searchable()->toggleable(),
                TextColumn::make('supplier.display_name')->label('Supplier')->searchable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable(),
                TextColumn::make('received_at')->label('Diterima')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('receiver.name')->label('Penerima')->toggleable(),
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('attachments_count')->label('Bukti')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
            ])
            ->recordActions([
                SecureFileGalleryModal::make(
                    'viewEvidence',
                    static function (GoodsReceipt $record): array {
                        $record->loadMissing('attachments');

                        return $record->attachments
                            ->map(static fn (GoodsReceiptAttachment $attachment): array => [
                                'label' => $attachment->caption ?: basename($attachment->file_path),
                                'path' => $attachment->file_path,
                                'inlineUrl' => route('files.goods-receipt-attachments.show', $attachment),
                                'downloadUrl' => route('files.goods-receipt-attachments.show', [
                                    'goodsReceiptAttachment' => $attachment,
                                    'download' => 1,
                                ]),
                            ])
                            ->all();
                    },
                    'Lihat Bukti',
                ),
                Action::make('uploadEvidence')
                    ->label('Upload Bukti')
                    ->color('primary')
                    ->visible(static fn (GoodsReceipt $record): bool => $record->status !== GoodsReceiptStatus::Cancelled && static::canManageEvidence($record))
                    ->schema(static fn (GoodsReceipt $record): array => [
                        Select::make('type')
                            ->label('Jenis bukti')
                            ->options(static::attachmentTypeOptions())
                            ->required(),
                        FileUpload::make('files')
                            ->label('File')
                            ->disk(VendorFileStorage::DISK)
                            ->directory('goods-receipts/'.$record->getKey())
                            ->visibility('private')
                            ->multiple()
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                                'application/pdf',
                            ])
                            ->maxFiles(10)
                            ->maxSize(10240)
                            ->required(),
                        Textarea::make('caption')->label('Keterangan')->rows(2),
                    ])
                    ->action(static function (GoodsReceipt $record, array $data): void {
                        abort_unless(static::canManageEvidence($record), 403);

                        static::runDomainAction(function () use ($record, $data): void {
                            foreach ((array) ($data['files'] ?? []) as $filePath) {
                                app(AddGoodsReceiptAttachmentAction::class)->execute(
                                    $record,
                                    $data['type'],
                                    $filePath,
                                    auth()->user(),
                                    $data['caption'] ?? null,
                                    VendorFileStorage::DISK,
                                );
                            }
                        }, 'Bukti penerimaan berhasil di-upload.');
                    }),
                Action::make('inspect')
                    ->label('Proses QC')
                    ->color('success')
                    ->visible(static fn (GoodsReceipt $record): bool => $record->status === GoodsReceiptStatus::PendingInspection && static::hasScopedPermission($record, SystemPermission::GoodsReceiptInspect))
                    ->schema(static function (GoodsReceipt $record): array {
                        $record->loadMissing('items.purchaseOrderItem');

                        return [
                            Repeater::make('inspection')
                                ->label('Hasil pemeriksaan')
                                ->schema([
                                    Hidden::make('receipt_item_id')->dehydrated(),
                                    Placeholder::make('item_label')
                                        ->label('Item')
                                        ->content(static fn ($state): string => (string) $state),
                                    TextInput::make('accepted_qty')
                                        ->label('Diterima QC')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(),
                                    TextInput::make('rejected_qty')
                                        ->label('Ditolak QC')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->required(),
                                    TextInput::make('condition')->label('Kondisi')->maxLength(100),
                                    TextInput::make('rejection_reason')->label('Alasan ditolak')->maxLength(255),
                                    TextInput::make('batch_number')->label('Nomor batch')->maxLength(100),
                                    DatePicker::make('expiry_date')->label('Kedaluwarsa')->native(false),
                                    TextInput::make('temperature')->label('Suhu (°C)')->numeric(),
                                    Textarea::make('notes')->label('Catatan QC')->rows(2)->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->default(static::inspectionDefaults($record))
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->columnSpanFull(),
                        ];
                    })
                    ->action(static function (GoodsReceipt $record, array $data): void {
                        static::requirePermission(SystemPermission::GoodsReceiptInspect);

                        static::runDomainAction(function () use ($record, $data): void {
                            $inspection = [];

                            foreach ($data['inspection'] ?? [] as $row) {
                                $itemId = (int) ($row['receipt_item_id'] ?? 0);
                                if ($itemId <= 0 || isset($inspection[$itemId])) {
                                    throw new DomainException('Data QC item tidak valid atau duplikat.');
                                }

                                unset($row['receipt_item_id'], $row['item_label']);
                                $inspection[$itemId] = $row;
                            }

                            app(InspectGoodsReceiptAction::class)->execute($record, $inspection, auth()->user());
                        }, 'QC penerimaan barang berhasil diselesaikan.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['purchaseOrder', 'deliverySchedule', 'supplier', 'kitchen', 'receiver'])
            ->withCount(['items', 'attachments']);
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            SystemPermission::GoodsReceiptCreate,
            SystemPermission::GoodsReceiptInspect,
            SystemPermission::GoodsReceiptReject,
            SystemPermission::PurchaseOrderExceptionClose,
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

    private static function canManageEvidence(GoodsReceipt $record): bool
    {
        $user = auth()->user();

        if (! $user || ! app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id)) {
            return false;
        }

        return $user->can(SystemPermission::GoodsReceiptCreate->value)
            || $user->can(SystemPermission::GoodsReceiptInspect->value);
    }

    private static function hasScopedPermission(GoodsReceipt $record, SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function inspectionDefaults(GoodsReceipt $record): array
    {
        return $record->items
            ->map(static function (GoodsReceiptItem $item): array {
                $name = $item->purchaseOrderItem?->product_name_snapshot ?? 'Item';
                $unit = $item->purchaseOrderItem?->unit_name_snapshot ?? '';
                $received = (float) $item->received_qty;

                return [
                    'receipt_item_id' => $item->getKey(),
                    'item_label' => sprintf('%s — diterima fisik %s %s', $name, static::formatQty($received), $unit),
                    'accepted_qty' => $received,
                    'rejected_qty' => 0,
                    'condition' => null,
                    'rejection_reason' => null,
                    'batch_number' => null,
                    'expiry_date' => null,
                    'temperature' => null,
                    'notes' => null,
                ];
            })
            ->values()
            ->all();
    }

    private static function attachmentTypeOptions(): array
    {
        return [
            GoodsReceiptAttachmentType::DeliveryPhoto->value => 'Foto Pengiriman',
            GoodsReceiptAttachmentType::WeightPhoto->value => 'Foto Timbang',
            GoodsReceiptAttachmentType::GoodsPhoto->value => 'Foto Barang',
            GoodsReceiptAttachmentType::HandoverPhoto->value => 'Foto Serah Terima',
            GoodsReceiptAttachmentType::DeliveryNote->value => 'Surat Jalan',
            GoodsReceiptAttachmentType::QualityEvidence->value => 'Bukti Kualitas',
            GoodsReceiptAttachmentType::Other->value => 'Lainnya',
        ];
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
        $status = $state instanceof GoodsReceiptStatus ? $state : GoodsReceiptStatus::tryFrom((string) $state);

        return match ($status) {
            GoodsReceiptStatus::PendingInspection => 'Menunggu QC',
            GoodsReceiptStatus::Completed => 'Selesai QC',
            GoodsReceiptStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof GoodsReceiptStatus ? $state : GoodsReceiptStatus::tryFrom((string) $state);

        return match ($status) {
            GoodsReceiptStatus::Completed => 'success',
            GoodsReceiptStatus::PendingInspection => 'warning',
            GoodsReceiptStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGoodsReceipts::route('/'),
        ];
    }
}
