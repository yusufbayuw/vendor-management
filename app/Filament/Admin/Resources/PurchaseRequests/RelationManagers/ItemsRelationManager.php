<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\RelationManagers;

use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Allocation Workspace';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Alokasi Supplier per Item')
            ->description('Pantau kebutuhan, alokasi berjalan, dan sisa kuantitas per item. Kuantitas tidak dijumlahkan lintas satuan.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['product', 'unit', 'allocations.supplier'])
                ->withSum('allocations as allocated_qty_sum', 'allocated_qty'))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable(),
                TextColumn::make('requested_qty')
                    ->label('Diminta')
                    ->formatStateUsing(fn ($state): string => static::formatQty((float) $state))
                    ->alignEnd(),
                TextColumn::make('allocated_qty_sum')
                    ->label('Dialokasikan')
                    ->state(fn (PurchaseRequestItem $record): float => static::allocatedQty($record))
                    ->formatStateUsing(fn ($state): string => static::formatQty((float) $state))
                    ->alignEnd(),
                TextColumn::make('remaining_qty')
                    ->label('Sisa')
                    ->state(fn (PurchaseRequestItem $record): float => static::remainingQty($record))
                    ->formatStateUsing(fn ($state): string => static::formatQty((float) $state))
                    ->badge()
                    ->color(fn ($state): string => (float) $state <= 0.0001 ? 'success' : 'warning')
                    ->alignEnd(),
                TextColumn::make('unit.symbol')
                    ->label('Satuan')
                    ->placeholder('-'),
                TextColumn::make('estimated_unit_price')
                    ->label('Estimasi Harga')
                    ->money('IDR')
                    ->toggleable(),
                TextColumn::make('allocation_summary')
                    ->label('Alokasi Saat Ini')
                    ->state(fn (PurchaseRequestItem $record): array => $record->allocations
                        ->map(fn ($allocation): string => sprintf(
                            '%s — %s %s @ Rp %s',
                            $allocation->supplier?->display_name ?? 'Supplier',
                            static::formatQty((float) $allocation->allocated_qty),
                            $record->unit?->symbol ?: $record->unit?->name,
                            number_format((float) $allocation->unit_price, 0, ',', '.'),
                        ))
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('Belum dialokasikan')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('allocateSupplier')
                    ->label('Alokasikan Supplier')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn (PurchaseRequestItem $record): bool => $this->canAllocateItem($record))
                    ->schema(fn (PurchaseRequestItem $record): array => [
                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(Supplier::query()
                                ->where('status', SupplierStatus::Active->value)
                                ->orderBy('display_name')
                                ->pluck('display_name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Jumlah alokasi')
                            ->helperText(sprintf(
                                'Sisa yang dapat dialokasikan: %s %s',
                                static::formatQty(static::remainingQty($record)),
                                $record->unit?->symbol ?: $record->unit?->name,
                            ))
                            ->numeric()
                            ->minValue(0.0001)
                            ->maxValue(static::remainingQty($record))
                            ->default(static::remainingQty($record))
                            ->required(),
                        TextInput::make('unit_price')
                            ->label('Harga satuan')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->default(filled($record->estimated_unit_price) ? (float) $record->estimated_unit_price : null)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Catatan alokasi')
                            ->rows(3),
                    ])
                    ->action(function (PurchaseRequestItem $record, array $data): void {
                        abort_unless($this->canAllocateItem($record), 403);

                        $supplier = Supplier::query()
                            ->where('status', SupplierStatus::Active->value)
                            ->findOrFail($data['supplier_id']);

                        try {
                            app(AllocatePurchaseRequestItemAction::class)->execute(
                                $record,
                                $supplier,
                                (float) $data['quantity'],
                                (float) $data['unit_price'],
                                auth()->user(),
                                $data['notes'] ?? null,
                            );

                            $this->getOwnerRecord()->refresh();

                            Notification::make()
                                ->success()
                                ->title('Alokasi supplier berhasil disimpan.')
                                ->send();
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();
                        }
                    }),
            ]);
    }

    private function canAllocateItem(PurchaseRequestItem $item): bool
    {
        $request = $this->getOwnerRecord();
        $user = auth()->user();

        return $request instanceof PurchaseRequest
            && in_array($request->status, [PurchaseRequestStatus::Approved, PurchaseRequestStatus::PartiallyAllocated], true)
            && $user !== null
            && $user->can(SystemPermission::PurchaseRequestAllocate->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $request->sppg_kitchen_id)
            && static::remainingQty($item) > 0.0001;
    }

    private static function allocatedQty(PurchaseRequestItem $item): float
    {
        if (array_key_exists('allocated_qty_sum', $item->getAttributes())) {
            return (float) ($item->getAttribute('allocated_qty_sum') ?? 0);
        }

        return (float) $item->allocations()->sum('allocated_qty');
    }

    private static function remainingQty(PurchaseRequestItem $item): float
    {
        return max(0, (float) $item->requested_qty - static::allocatedQty($item));
    }

    private static function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', '.'), '0'), ',');
    }
}
