<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\OperationalRiskAnalyticsExporter;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\OperationalRiskAnalyticsService;
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

class OperationalRiskAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Operational Risk';

    protected static ?string $title = 'Operational Risk Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $slug = 'analytics/operational-risk';

    protected string $view = 'filament.admin.pages.operational-risk-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Rule-based dan explainable: setiap level risiko berasal dari flag domain yang dapat ditelusuri, bukan skor prediktif atau black-box.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query($this->metrics()->query($user))
            ->heading('Operational Risk & Exception Monitor')
            ->description('Critical: supplier unavailable atau invoice overdue dengan saldo. High: overdue delivery, open discrepancy, underfill lewat deadline, atau exception pending. Medium: acknowledgement pending, QC rejection, atau historical close-with-exception.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(OperationalRiskAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('risk_level')
                    ->label('Risk')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->riskLevel($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::riskLabel($state))
                    ->color(fn (string $state): string => self::riskColor($state)),
                TextColumn::make('risk_flag_count')
                    ->label('Flags')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->riskFlagCount($record))
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('order_date')
                    ->label('Tanggal PO')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Nomor PO')
                    ->searchable()
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
                TextColumn::make('status')
                    ->label('Status PO')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus|string $state): string => self::statusLabel(
                        $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::from($state),
                    )),
                TextColumn::make('risk_reasons')
                    ->label('Alasan Risiko')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->riskReasons($record))
                    ->wrap(),
                TextColumn::make('overdue_schedules_count')
                    ->label('Delivery Lewat')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->overdueScheduleCount($record))
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd(),
                TextColumn::make('open_discrepancies_count')
                    ->label('Discrepancy Open')
                    ->state(fn (PurchaseOrder $record): int => $this->metrics()->openDiscrepancyCount($record))
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
                TextColumn::make('delivery_end')
                    ->label('Batas Delivery')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice.due_date')
                    ->label('Jatuh Tempo Invoice')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice_outstanding')
                    ->label('Outstanding')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->invoiceOutstanding($record))
                    ->money('IDR')
                    ->placeholder('-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice_overdue_days')
                    ->label('Overdue Invoice')
                    ->state(fn (PurchaseOrder $record): ?int => $this->metrics()->invoiceOverdueDays($record))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : $state.' hari')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('needs_attention')
                    ->label('Hanya perlu perhatian')
                    ->query(fn (Builder $query): Builder => $this->metrics()->applyNeedsAttention($query)),
                Filter::make('period')
                    ->label('Periode PO')
                    ->schema([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('to')->label('Sampai tanggal'),
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
                SelectFilter::make('status')
                    ->label('Status PO')
                    ->options(self::statusOptions()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(5)
            ->groups([
                Group::make('supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
            ])
            ->defaultSort('order_date', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): OperationalRiskAnalyticsService
    {
        return app(OperationalRiskAnalyticsService::class);
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
    private static function statusOptions(): array
    {
        return collect(PurchaseOrderStatus::cases())
            ->reject(fn (PurchaseOrderStatus $status): bool => in_array($status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Cancelled,
            ], true))
            ->mapWithKeys(fn (PurchaseOrderStatus $status): array => [
                $status->value => self::statusLabel($status),
            ])
            ->all();
    }

    private static function statusLabel(PurchaseOrderStatus $status): string
    {
        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
            PurchaseOrderStatus::PendingApproval => 'Menunggu Approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Diterbitkan',
            PurchaseOrderStatus::Acknowledged => 'Diakui Supplier',
            PurchaseOrderStatus::Scheduled => 'Terjadwal',
            PurchaseOrderStatus::PartiallyDelivered => 'Terkirim Sebagian',
            PurchaseOrderStatus::Fulfilled => 'Fulfilled',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu Exception Closure',
            PurchaseOrderStatus::ClosedWithException => 'Closed with Exception',
            PurchaseOrderStatus::Invoiced => 'Invoiced',
            PurchaseOrderStatus::Paid => 'Paid',
            PurchaseOrderStatus::Closed => 'Closed',
            PurchaseOrderStatus::Cancelled => 'Cancelled',
        };
    }

    private static function riskLabel(string $level): string
    {
        return match ($level) {
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            default => 'Normal',
        };
    }

    private static function riskColor(string $level): string
    {
        return match ($level) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'success',
        };
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 1, ',', '.').' %';
    }
}
