<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrderItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\CommodityAnalyticsService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CommodityAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Analitik Komoditas';

    protected static ?string $title = 'Analitik Komoditas';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $slug = 'analytics/commodities';

    protected string $view = 'filament.admin.pages.commodity-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Analitik realisasi pengadaan per komoditas. Kuantitas diterima menggunakan accepted quantity setelah QC, bukan konsumsi stok dapur.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(CommodityAnalyticsService::class)->query($user))
            ->heading('Realisasi Pengadaan Komoditas')
            ->description('Default periode tiga bulan terakhir. Gunakan grouping untuk membandingkan SPPG, kategori, komoditas, atau supplier.')
            ->columns([
                TextColumn::make('purchaseOrder.order_date')
                    ->label('Tanggal PO')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.category.name')
                    ->label('Kategori')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name_snapshot')
                    ->label('Komoditas')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrderItem $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-'))
                    ->searchable(),
                TextColumn::make('ordered_qty')
                    ->label('Dipesan')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),
                TextColumn::make('delivered_qty')
                    ->label('Dikirim')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),
                TextColumn::make('accepted_qty')
                    ->label('Diterima QC')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),
                TextColumn::make('rejected_qty')
                    ->label('Ditolak QC')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 2)),
                TextColumn::make('unit_name_snapshot')
                    ->label('Satuan')
                    ->toggleable(),
                TextColumn::make('unit_price')
                    ->label('Harga Satuan')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(),
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
                            ->default(today()->subMonths(3)->toDateString()),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(today()->toDateString()),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('purchaseOrder', fn (Builder $orderQuery): Builder => $orderQuery
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date),
                            ));
                    }),
                Filter::make('sppg')
                    ->schema([
                        Select::make('value')
                            ->label('SPPG')
                            ->options(fn (): array => $this->kitchenOptions())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'purchaseOrder',
                            fn (Builder $orderQuery): Builder => $orderQuery->where('sppg_kitchen_id', (int) $id),
                        ),
                    )),
                Filter::make('category')
                    ->schema([
                        Select::make('value')
                            ->label('Kategori')
                            ->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'product',
                            fn (Builder $productQuery): Builder => $productQuery->where('category_id', (int) $id),
                        ),
                    )),
                Filter::make('product')
                    ->schema([
                        Select::make('value')
                            ->label('Komoditas')
                            ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->where('product_id', (int) $id),
                    )),
                Filter::make('supplier')
                    ->schema([
                        Select::make('value')
                            ->label('Supplier')
                            ->options(fn (): array => Supplier::query()
                                ->orderByRaw('COALESCE(display_name, legal_name)')
                                ->get()
                                ->mapWithKeys(fn (Supplier $supplier): array => [
                                    $supplier->getKey() => $supplier->display_name ?: $supplier->legal_name,
                                ])
                                ->all())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $id): Builder => $query->whereHas(
                            'purchaseOrder',
                            fn (Builder $orderQuery): Builder => $orderQuery->where('supplier_id', (int) $id),
                        ),
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(5)
            ->groups([
                Group::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('product.category.name')
                    ->label('Kategori')
                    ->collapsible(),
                Group::make('product_name_snapshot')
                    ->label('Komoditas')
                    ->collapsible(),
                Group::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
            ])
            ->defaultGroup('purchaseOrder.kitchen.name')
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    /** @return array<int, string> */
    private function kitchenOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $ids = app(UserAccessService::class)->accessibleKitchenIds($user);

        return SppgKitchen::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
