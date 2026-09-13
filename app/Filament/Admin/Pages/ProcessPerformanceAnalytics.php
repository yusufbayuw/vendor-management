<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\ProcessPerformanceAnalyticsExporter;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
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

class ProcessPerformanceAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Process Performance';

    protected static ?string $title = 'Process Performance';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 80;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'analytics/process-performance';

    protected string $view = 'filament.admin.pages.process-performance-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Durasi setiap tahap procurement-to-pay berdasarkan timestamp transaksi yang benar-benar tersimpan pada PR, PO, penerimaan/QC, invoice, dan payment.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(ProcessPerformanceAnalyticsService::class)->query($user))
            ->heading('Procurement Cycle Performance')
            ->description('Tahap terlama dihitung hanya dari stage yang sudah selesai. Nilai negatif, bila ada, dibiarkan terlihat sebagai sinyal masalah kualitas timestamp.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(ProcessPerformanceAnalyticsExporter::class)
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
                TextColumn::make('kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrder $record): string => $state ?: ($record->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('purchaseRequest.number')
                    ->label('Nomor PR')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('pr_approval_hours')
                    ->label('PR Approval')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->prApprovalHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('po_generation_hours')
                    ->label('PR → PO')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->poGenerationHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('po_approval_hours')
                    ->label('PO Approval')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->poApprovalHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('issue_hours')
                    ->label('Approval → Issue')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->issueHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('acknowledgement_hours')
                    ->label('Supplier Ack')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->acknowledgementHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('first_receipt_hours')
                    ->label('Ack → First Receipt')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->firstReceiptHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('average_qc_hours')
                    ->label('Avg QC')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->averageQcHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('delivery_qc_cycle_hours')
                    ->label('Delivery/QC Cycle')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->deliveryQcCycleHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('invoice_approval_hours')
                    ->label('Invoice Approval')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->invoiceApprovalHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_cycle_hours')
                    ->label('Payment Cycle')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->paymentCycleHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('end_to_end_hours')
                    ->label('End-to-End')
                    ->state(fn (PurchaseOrder $record): ?float => $this->metrics()->endToEndHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('longest_stage')
                    ->label('Tahap Terlama')
                    ->state(fn (PurchaseOrder $record): string => $this->metrics()->longestCompletedStage($record))
                    ->wrap(),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode PO')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->default(today()->subMonths(3)->toDateString()),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(today()->toDateString()),
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
                    ->label('Status')
                    ->options(self::statusOptions())
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
                Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (PurchaseOrder $record): string => self::statusLabel($record->status))
                    ->collapsible(),
            ])
            ->defaultGroup('kitchen.name')
            ->defaultSort('order_date', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): ProcessPerformanceAnalyticsService
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
    private static function statusOptions(): array
    {
        return collect(PurchaseOrderStatus::cases())
            ->reject(fn (PurchaseOrderStatus $status): bool => $status === PurchaseOrderStatus::Cancelled)
            ->mapWithKeys(fn (PurchaseOrderStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
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
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof PurchaseOrderStatus ? $state : PurchaseOrderStatus::tryFrom((string) $state);

        return match ($status) {
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Acknowledged,
            PurchaseOrderStatus::Fulfilled,
            PurchaseOrderStatus::Paid,
            PurchaseOrderStatus::Closed => 'success',
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Issued,
            PurchaseOrderStatus::Scheduled,
            PurchaseOrderStatus::PartiallyDelivered,
            PurchaseOrderStatus::PendingExceptionClosure,
            PurchaseOrderStatus::Invoiced => 'warning',
            PurchaseOrderStatus::Cancelled => 'danger',
            PurchaseOrderStatus::ClosedWithException => 'info',
            default => 'gray',
        };
    }
}
