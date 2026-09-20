<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\ProcessBottleneckAnalyticsExporter;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\ProcessBottleneckAnalyticsService;
use App\Services\Analytics\ProcessPerformanceAnalyticsService;
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

class ProcessBottleneckAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Process Bottleneck';

    protected static ?string $title = 'Process Bottleneck Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 92;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $slug = 'analytics/process-bottlenecks';

    protected string $view = 'filament.admin.pages.process-bottleneck-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Deteksi cycle-time yang tidak biasa per tahap menggunakan histori 365 hari pada SPPG yang sama. Slow anomaly adalah kandidat bottleneck; fast anomaly tetap ditampilkan sebagai sinyal improvement atau kualitas timestamp.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query($this->metrics()->query($user))
            ->heading('Cycle-Time Bottleneck Detection')
            ->description('Setiap stage dibandingkan dengan baseline historis stage yang sama memakai median + MAD, minimum 5 observasi, robust z-score 3,5. Durasi negatif diperlakukan sebagai data-quality issue, bukan outlier statistik.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(ProcessBottleneckAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('diagnostic_status')
                    ->label('Diagnostic')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->overallStatus($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::diagnosticLabel($state))
                    ->color(fn (string $state): string => self::diagnosticColor($state)),
                TextColumn::make('order_date')
                    ->label('Tanggal PO')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status PO')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::poStatusLabel($state)),
                TextColumn::make('bottleneck_stage')
                    ->label('Bottleneck Stage')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->bottleneckStage($record)),
                TextColumn::make('slow_stage_count')
                    ->label('Slow Stages')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->slowStageCount($record))
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('evaluated_stage_count')
                    ->label('Evaluated')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->evaluatedStageCount($record))
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('max_robust_z')
                    ->label('Max Robust Z')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->maximumRobustZ($record))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '-' : number_format($state, 2, ',', '.'))
                    ->alignEnd(),
                TextColumn::make('diagnostic_summary')
                    ->label('Diagnostic Summary')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->diagnosticSummary($record))
                    ->wrap(),
                TextColumn::make('longest_stage')
                    ->label('Tahap Terlama Absolut')
                    ->state(fn (PurchaseOrder $record): string => $this->performance()->longestCompletedStage($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchaseRequest.number')
                    ->label('Nomor PR')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode PO')
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
                SelectFilter::make('sppg_kitchen_id')
                    ->label('SPPG')
                    ->options(fn (): array => $this->kitchenOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => $this->supplierOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status PO')
                    ->options(self::poStatusOptions())
                    ->multiple(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->groups([
                Group::make('kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
            ])
            ->defaultSort('order_date', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): ProcessBottleneckAnalyticsService
    {
        return app(ProcessBottleneckAnalyticsService::class);
    }

    private function performance(): ProcessPerformanceAnalyticsService
    {
        return app(ProcessPerformanceAnalyticsService::class);
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
    private static function poStatusOptions(): array
    {
        return collect(PurchaseOrderStatus::cases())
            ->reject(fn (PurchaseOrderStatus $status): bool => in_array($status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Cancelled,
            ], true))
            ->mapWithKeys(fn (PurchaseOrderStatus $status): array => [
                $status->value => self::poStatusLabel($status),
            ])
            ->all();
    }

    private static function poStatusLabel($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::PendingApproval => 'Menunggu Approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Diterbitkan',
            PurchaseOrderStatus::Acknowledged => 'Dikonfirmasi Supplier',
            PurchaseOrderStatus::Scheduled => 'Terjadwal',
            PurchaseOrderStatus::PartiallyDelivered => 'Terkirim Sebagian',
            PurchaseOrderStatus::Fulfilled => 'Terpenuhi',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu Penutupan Exception',
            PurchaseOrderStatus::ClosedWithException => 'Ditutup dengan Exception',
            PurchaseOrderStatus::Invoiced => 'Ditagihkan',
            PurchaseOrderStatus::Paid => 'Dibayar',
            PurchaseOrderStatus::Closed => 'Selesai',
            default => (string) $state,
        };
    }

    private static function diagnosticLabel(string $status): string
    {
        return match ($status) {
            'bottleneck' => 'Bottleneck',
            'unusually_fast' => 'Unusually Fast',
            'normal' => 'Normal',
            'data_quality' => 'Data Quality',
            default => 'Baseline Belum Cukup',
        };
    }

    private static function diagnosticColor(string $status): string
    {
        return match ($status) {
            'bottleneck' => 'danger',
            'data_quality' => 'warning',
            'unusually_fast' => 'info',
            'normal' => 'success',
            default => 'gray',
        };
    }
}
