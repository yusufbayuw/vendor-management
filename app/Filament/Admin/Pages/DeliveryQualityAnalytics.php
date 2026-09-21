<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Filament\Exports\DeliveryQualityAnalyticsExporter;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Analytics\DeliveryQualityAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DeliveryQualityAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Delivery & Quality';

    protected static ?string $title = 'Delivery & Quality Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'analytics/delivery-quality';

    protected string $view = 'filament.admin.pages.delivery-quality-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Analitik penerimaan dan QC per line item. Rasio dihitung per line agar KG, PCS, liter, dan satuan lain tidak dijumlahkan secara keliru.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(DeliveryQualityAnalyticsService::class)->query($user))
            ->heading('Realisasi Delivery & QC')
            ->description('Gunakan filter penolakan untuk fokus pada quality failure dan grouping supplier/komoditas untuk pola masalah berulang.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(DeliveryQualityAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('goodsReceipt.received_at')
                    ->label('Diterima')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('goodsReceipt.kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('goodsReceipt.supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, GoodsReceiptItem $record): string => $state ?: ($record->goodsReceipt?->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrderItem.product_name_snapshot')
                    ->label('Komoditas')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrderItem.unit_name_snapshot')
                    ->label('Satuan'),
                TextColumn::make('planned_qty')
                    ->label('Planned')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('received_qty')
                    ->label('Received')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('accepted_qty')
                    ->label('Accepted')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('rejected_qty')
                    ->label('Rejected')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('acceptance_rate')
                    ->label('Acceptance')
                    ->state(fn (GoodsReceiptItem $record): ?float => $this->metrics()->acceptanceRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 98 => 'success',
                        $state >= 90 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('reject_rate')
                    ->label('Reject Rate')
                    ->state(fn (GoodsReceiptItem $record): ?float => $this->metrics()->rejectRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('variance_rate')
                    ->label('Variance')
                    ->state(fn (GoodsReceiptItem $record): ?float => $this->metrics()->varianceRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::signedPercent($state))
                    ->alignEnd(),
                TextColumn::make('condition')
                    ->label('Kondisi')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('rejection_reason')
                    ->label('Alasan Penolakan')
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('temperature')
                    ->label('Suhu')
                    ->suffix(' °C')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('batch_number')
                    ->label('Batch')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expiry_date')
                    ->label('Kedaluwarsa')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('goodsReceipt.number')
                    ->label('Nomor Penerimaan')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->goodsReceipts(auth()->user())['from']),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->goodsReceipts(auth()->user())['to']),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query->whereHas(
                        'goodsReceipt',
                        fn (Builder $receiptQuery): Builder => $receiptQuery
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('received_at', '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('received_at', '<=', $date),
                            ),
                    )),
                SelectFilter::make('product')
                    ->label('Komoditas')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'purchaseOrderItem',
                            fn (Builder $itemQuery): Builder => $itemQuery->where('product_id', (int) $id),
                        ),
                    )),
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'purchaseOrderItem.product',
                            fn (Builder $productQuery): Builder => $productQuery->where('category_id', (int) $id),
                        ),
                    )),
                SelectFilter::make('supplier')
                    ->label('Supplier')
                    ->options(fn (): array => $this->supplierOptions())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'goodsReceipt',
                            fn (Builder $receiptQuery): Builder => $receiptQuery->where('supplier_id', (int) $id),
                        ),
                    )),
                SelectFilter::make('sppg')
                    ->label('SPPG')
                    ->options(fn (): array => $this->kitchenOptions())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'goodsReceipt',
                            fn (Builder $receiptQuery): Builder => $receiptQuery->where('sppg_kitchen_id', (int) $id),
                        ),
                    )),
                TernaryFilter::make('has_rejection')
                    ->label('Ada Penolakan')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('rejected_qty', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('rejected_qty', '<=', 0),
                    ),
                SelectFilter::make('condition')
                    ->label('Kondisi')
                    ->options(fn (): array => GoodsReceiptItem::query()
                        ->whereNotNull('condition')
                        ->where('condition', '!=', '')
                        ->distinct()
                        ->orderBy('condition')
                        ->pluck('condition', 'condition')
                        ->all()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(6)
            ->groups([
                Group::make('purchaseOrderItem.product_name_snapshot')
                    ->label('Komoditas')
                    ->collapsible(),
                Group::make('goodsReceipt.supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('goodsReceipt.kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('condition')
                    ->label('Kondisi')
                    ->collapsible(),
            ])
            ->defaultGroup('purchaseOrderItem.product_name_snapshot')
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): DeliveryQualityAnalyticsService
    {
        return app(DeliveryQualityAnalyticsService::class);
    }

    /** @return array<int, string> */
    private function kitchenOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return SppgKitchen::query()
            ->whereIn('id', app(UserAccessService::class)->accessibleKitchenIds($user))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private function supplierOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $kitchenIds = app(UserAccessService::class)->accessibleKitchenIds($user);

        return Supplier::query()
            ->whereHas('purchaseOrders', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
            ->orderByRaw('COALESCE(display_name, legal_name)')
            ->get()
            ->mapWithKeys(fn (Supplier $supplier): array => [
                $supplier->getKey() => $supplier->display_name ?: $supplier->legal_name,
            ])
            ->all();
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 1, ',', '.').' %';
    }

    private static function signedPercent(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        $prefix = $value > 0 ? '+' : '';

        return $prefix.number_format($value, 1, ',', '.').' %';
    }
}
