<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Filament\Exports\PriceAnomalyAnalyticsExporter;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrderItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\PriceAnomalyAnalyticsService;
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
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PriceAnomalyAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Price Anomaly';

    protected static ?string $title = 'Price Anomaly Detection';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 91;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $slug = 'analytics/price-anomalies';

    protected string $view = 'filament.admin.pages.price-anomaly-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Deteksi outlier harga menggunakan median + MAD pada histori 180 hari sebelumnya untuk SPPG, komoditas, dan satuan yang sama. Minimum 5 transaksi diperlukan sebelum anomaly dapat dinilai.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query($this->metrics()->query($user))
            ->heading('Robust Price Anomaly Detection')
            ->description('Anomaly ditandai ketika |robust z-score| ≥ 3,5. Jika MAD = 0, harga yang berbeda dari baseline identik ditandai anomaly. Baseline hanya menggunakan transaksi sebelum PO saat ini untuk menghindari data leakage.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(PriceAnomalyAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('anomaly_status')
                    ->label('Status')
                    ->state(fn (PurchaseOrderItem $record): string => $this->metrics()->analysis($record)['status'])
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),
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
                TextColumn::make('unit_price')
                    ->label('Harga Satuan')
                    ->money('IDR')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('baseline_count')
                    ->label('Baseline N')
                    ->state(fn (PurchaseOrderItem $record): int => $this->metrics()->analysis($record)['baseline_count'])
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('baseline_median')
                    ->label('Median')
                    ->state(fn (PurchaseOrderItem $record): ?float => $this->metrics()->analysis($record)['median'])
                    ->money('IDR')
                    ->placeholder('-')
                    ->alignEnd(),
                TextColumn::make('deviation_percentage')
                    ->label('Deviasi')
                    ->state(fn (PurchaseOrderItem $record): ?float => $this->metrics()->analysis($record)['deviation_percentage'])
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('robust_z')
                    ->label('Robust Z')
                    ->state(fn (PurchaseOrderItem $record): ?float => $this->metrics()->analysis($record)['robust_z'])
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '-' : number_format($state, 2, ',', '.'))
                    ->alignEnd(),
                TextColumn::make('anomaly_reason')
                    ->label('Penjelasan')
                    ->state(fn (PurchaseOrderItem $record): string => $this->metrics()->analysis($record)['reason'])
                    ->wrap(),
                TextColumn::make('baseline_mad')
                    ->label('MAD')
                    ->state(fn (PurchaseOrderItem $record): ?float => $this->metrics()->analysis($record)['mad'])
                    ->money('IDR')
                    ->placeholder('-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchaseOrder.number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode transaksi')
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
            ])
            ->defaultGroup('product_name_snapshot')
            ->defaultSort('purchase_order_id', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): PriceAnomalyAnalyticsService
    {
        return app(PriceAnomalyAnalyticsService::class);
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

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'high' => 'Anomaly Tinggi',
            'low' => 'Anomaly Rendah',
            'normal' => 'Normal',
            default => 'Baseline Belum Cukup',
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'high' => 'danger',
            'low' => 'warning',
            'normal' => 'success',
            default => 'gray',
        };
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2, ',', '.').' %';
    }
}
