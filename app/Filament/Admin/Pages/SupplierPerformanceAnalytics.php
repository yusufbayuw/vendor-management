<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Filament\Exports\SupplierPerformanceAnalyticsExporter;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Analytics\SupplierPerformanceAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Count;
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

class SupplierPerformanceAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Performa Supplier';

    protected static ?string $title = 'Performa Supplier';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $slug = 'analytics/supplier-performance';

    protected string $view = 'filament.admin.pages.supplier-performance-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Fill Rate dan Reject Rate dihitung per line item agar satuan berbeda tidak tercampur. OTIF dievaluasi per jadwal pengiriman yang sudah memiliki goods receipt.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(SupplierPerformanceAnalyticsService::class)->query($user))
            ->heading('Supplier Delivery & Quality Performance')
            ->description('OTIF bersifat strict terhadap planned delivery time. Tolerance SLA dapat dibuat configurable pada fase governance analytics berikutnya.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(SupplierPerformanceAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('order_date')
                    ->label('Tanggal PO')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->sortable()
                    ->summarize(Count::make()->label('Jumlah PO')),
                TextColumn::make('total_amount')
                    ->label('Nilai PO')
                    ->money('IDR')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('IDR')),
                TextColumn::make('evaluated_delivery_count')
                    ->label('Pengiriman')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->evaluatedDeliveryCount($record))
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('fill_rate')
                    ->label('Fill Rate')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->fillRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('reject_rate')
                    ->label('Reject Rate')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->rejectRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('on_time_rate')
                    ->label('On Time')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->onTimeRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('in_full_rate')
                    ->label('In Full')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->inFullRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->alignEnd(),
                TextColumn::make('otif_rate')
                    ->label('OTIF')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->otifRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::percent($state))
                    ->badge()
                    ->color(fn (?float $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 95 => 'success',
                        $state >= 80 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('discrepancies_count')
                    ->label('Discrepancy')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 0)),
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date),
                        )),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => $this->supplierOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('sppg_kitchen_id')
                    ->label('SPPG')
                    ->options(fn (): array => $this->kitchenOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('product')
                    ->label('Komoditas')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $productId): Builder => $query->whereHas(
                            'items',
                            fn (Builder $itemQuery): Builder => $itemQuery->where('product_id', (int) $productId),
                        ),
                    )),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->groups([
                Group::make('supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
            ])
            ->defaultGroup('supplier.display_name')
            ->defaultSort('order_date', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): SupplierPerformanceAnalyticsService
    {
        return app(SupplierPerformanceAnalyticsService::class);
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
}
