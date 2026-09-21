<?php

namespace App\Filament\Admin\Pages;

use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\DiscrepancyType;
use App\Enums\SystemPermission;
use App\Filament\Exports\DiscrepancyAnalyticsExporter;
use App\Models\FulfillmentDiscrepancy;
use App\Models\Product;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Analytics\DiscrepancyAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Count;
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

class DiscrepancyAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Discrepancy';

    protected static ?string $title = 'Discrepancy Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 60;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $slug = 'analytics/discrepancies';

    protected string $view = 'filament.admin.pages.discrepancy-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Analitik exception fulfillment berdasarkan jenis masalah, variance, supplier, SPPG, resolution, dan waktu penyelesaian.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(DiscrepancyAnalyticsService::class)->query($user))
            ->heading('Fulfillment Discrepancy')
            ->description('Gunakan grouping jenis/supplier/SPPG untuk menemukan konsentrasi exception dan pola masalah berulang.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(DiscrepancyAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, FulfillmentDiscrepancy $record): string => $state ?: ($record->purchaseOrder?->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseOrderItem.product_name_snapshot')
                    ->label('Komoditas')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::typeLabel($state))
                    ->summarize(Count::make()->label('Jumlah kasus')),
                TextColumn::make('expected_qty')
                    ->label('Expected')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('actual_qty')
                    ->label('Actual')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('variance_qty')
                    ->label('Variance')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('purchaseOrderItem.unit_name_snapshot')
                    ->label('Satuan')
                    ->placeholder('-'),
                TextColumn::make('variance_rate')
                    ->label('Variance %')
                    ->state(fn (FulfillmentDiscrepancy $record): ?float => $this->metrics()->varianceRate($record))
                    ->formatStateUsing(fn (?float $state): string => self::signedPercent($state))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('resolution')
                    ->label('Resolution')
                    ->formatStateUsing(fn ($state): string => self::resolutionLabel($state))
                    ->placeholder('-'),
                TextColumn::make('resolution_hours')
                    ->label('Waktu Penyelesaian')
                    ->state(fn (FulfillmentDiscrepancy $record): ?float => $this->metrics()->resolutionHours($record))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '-' : number_format($state, 1, ',', '.').' jam')
                    ->alignEnd(),
                TextColumn::make('purchaseOrder.number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->label('Deskripsi')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolution_notes')
                    ->label('Catatan Resolution')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->discrepancies(auth()->user())['from']),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->discrepancies(auth()->user())['to']),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date),
                        )),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(self::typeOptions())
                    ->multiple(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->multiple(),
                SelectFilter::make('resolution')
                    ->label('Resolution')
                    ->options(self::resolutionOptions())
                    ->multiple(),
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
            ->filtersFormColumns(6)
            ->groups([
                Group::make('type')
                    ->label('Jenis')
                    ->getTitleFromRecordUsing(fn (FulfillmentDiscrepancy $record): string => self::typeLabel($record->type))
                    ->collapsible(),
                Group::make('purchaseOrder.supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('purchaseOrder.kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (FulfillmentDiscrepancy $record): string => self::statusLabel($record->status))
                    ->collapsible(),
            ])
            ->defaultGroup('type')
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): DiscrepancyAnalyticsService
    {
        return app(DiscrepancyAnalyticsService::class);
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

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        return collect(DiscrepancyType::cases())
            ->mapWithKeys(fn (DiscrepancyType $type): array => [$type->value => self::typeLabel($type)])
            ->all();
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(DiscrepancyStatus::cases())
            ->mapWithKeys(fn (DiscrepancyStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    /** @return array<string, string> */
    private static function resolutionOptions(): array
    {
        return collect(DiscrepancyResolution::cases())
            ->mapWithKeys(fn (DiscrepancyResolution $resolution): array => [$resolution->value => self::resolutionLabel($resolution)])
            ->all();
    }

    private static function typeLabel($state): string
    {
        $type = $state instanceof DiscrepancyType ? $state : DiscrepancyType::tryFrom((string) $state);

        return match ($type) {
            DiscrepancyType::UnderDelivery => 'Under Delivery',
            DiscrepancyType::OverDelivery => 'Over Delivery',
            DiscrepancyType::RejectedGoods => 'Rejected Goods',
            DiscrepancyType::LateDelivery => 'Late Delivery',
            DiscrepancyType::MissingDelivery => 'Missing Delivery',
            DiscrepancyType::WrongProduct => 'Wrong Product',
            DiscrepancyType::QualityIssue => 'Quality Issue',
            default => (string) $state,
        };
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof DiscrepancyStatus ? $state : DiscrepancyStatus::tryFrom((string) $state);

        return match ($status) {
            DiscrepancyStatus::Open => 'Terbuka',
            DiscrepancyStatus::Resolved => 'Selesai',
            DiscrepancyStatus::Waived => 'Waived',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof DiscrepancyStatus ? $state : DiscrepancyStatus::tryFrom((string) $state);

        return match ($status) {
            DiscrepancyStatus::Open => 'danger',
            DiscrepancyStatus::Resolved => 'success',
            DiscrepancyStatus::Waived => 'warning',
            default => 'gray',
        };
    }

    private static function resolutionLabel($state): string
    {
        if ($state === null || $state === '') {
            return '-';
        }

        $resolution = $state instanceof DiscrepancyResolution ? $state : DiscrepancyResolution::tryFrom((string) $state);

        return match ($resolution) {
            DiscrepancyResolution::ReplacementRequired => 'Replacement Required',
            DiscrepancyResolution::RemainingCancelled => 'Remaining Cancelled',
            DiscrepancyResolution::AcceptedException => 'Accepted Exception',
            DiscrepancyResolution::FinancialAdjustment => 'Financial Adjustment',
            DiscrepancyResolution::Other => 'Other',
            default => (string) $state,
        };
    }

    private static function signedPercent(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return ($value > 0 ? '+' : '').number_format($value, 1, ',', '.').' %';
    }
}
