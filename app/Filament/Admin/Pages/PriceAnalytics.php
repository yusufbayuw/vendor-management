<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\PriceAnalyticsExporter;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrderItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\CommodityAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Range;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PriceAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Analitik Harga';

    protected static ?string $title = 'Analitik Harga';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $slug = 'analytics/prices';

    protected string $view = 'filament.admin.pages.price-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Histori harga aktual pada PO. Untuk membandingkan supplier atau SPPG secara valid, filter komoditas dan satuan yang sama terlebih dahulu.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $query = app(CommodityAnalyticsService::class)
            ->query($user)
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query->whereNotIn('status', [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::PendingApproval->value,
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Cancelled->value,
            ]));

        return $table
            ->query($query)
            ->heading('Histori & Perbandingan Harga Komoditas')
            ->description('Rentang harga pada summary paling bermakna ketika tabel difilter atau dikelompokkan pada komoditas dan satuan yang sama.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(PriceAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('purchaseOrder.order_date')
                    ->label('Tanggal PO')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('product.category.name')
                    ->label('Kategori')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name_snapshot')
                    ->label('Komoditas')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit_name_snapshot')
                    ->label('Satuan')
                    ->sortable(),
                TextColumn::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrderItem $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ordered_qty')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('unit_price')
                    ->label('Harga Satuan')
                    ->money('IDR')
                    ->alignEnd()
                    ->sortable()
                    ->summarize(
                        Range::make()
                            ->label('Rentang')
                            ->formatStateUsing(fn (array $state): array => array_map(
                                fn ($value): string => 'Rp '.number_format((float) $value, 0, ',', '.'),
                                $state,
                            )),
                    ),
                TextColumn::make('subtotal')
                    ->label('Nilai')
                    ->money('IDR')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('IDR')),
                TextColumn::make('purchaseOrder.number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->purchaseOrders(auth()->user())['from']),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->purchaseOrders(auth()->user())['to']),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query->whereHas(
                        'purchaseOrder',
                        fn (Builder $orderQuery): Builder => $orderQuery
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date),
                            ),
                    )),
                SelectFilter::make('product_id')
                    ->label('Komoditas')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('unit_id')
                    ->label('Satuan')
                    ->options(fn (): array => Unit::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'product',
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
                            'purchaseOrder',
                            fn (Builder $orderQuery): Builder => $orderQuery->where('supplier_id', (int) $id),
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
                            'purchaseOrder',
                            fn (Builder $orderQuery): Builder => $orderQuery->where('sppg_kitchen_id', (int) $id),
                        ),
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(5)
            ->groups([
                Group::make('product_name_snapshot')
                    ->label('Komoditas')
                    ->collapsible(),
                Group::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('product.category.name')
                    ->label('Kategori')
                    ->collapsible(),
            ])
            ->defaultGroup('product_name_snapshot')
            ->defaultSort('purchase_order_id', 'desc')
            ->paginationPageOptions([25, 50, 100]);
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
}
