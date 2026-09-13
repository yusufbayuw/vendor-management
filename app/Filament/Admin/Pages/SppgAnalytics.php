<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\SppgAnalyticsExporter;
use App\Models\PurchaseOrder;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\SppgAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Average;
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

class SppgAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Analitik SPPG';

    protected static ?string $title = 'Analitik SPPG';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $slug = 'analytics/sppg';

    protected string $view = 'filament.admin.pages.sppg-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Perbandingan aktivitas procurement antar SPPG berdasarkan PO, nilai, supplier, discrepancy, invoice, dan settlement dalam scope akses user.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(SppgAnalyticsService::class)->query($user))
            ->heading('Aktivitas Procurement per SPPG')
            ->description('Gunakan grouping SPPG untuk memperoleh subtotal jumlah item dan nilai PO. Default periode tiga bulan terakhir.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(SppgAnalyticsExporter::class)
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
                TextColumn::make('kitchen.organization.name')
                    ->label('Organisasi')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('kitchen.name')
                    ->label('SPPG')
                    ->searchable()
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
                TextColumn::make('items_count')
                    ->label('Item')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total item')->numeric(decimalPlaces: 0)),
                TextColumn::make('total_amount')
                    ->label('Nilai PO')
                    ->money('IDR')
                    ->alignEnd()
                    ->sortable()
                    ->summarize([
                        Sum::make()->label('Total')->money('IDR'),
                        Average::make()->label('Rata-rata')->money('IDR'),
                    ]),
                TextColumn::make('status')
                    ->label('Status PO')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('open_discrepancies_count')
                    ->label('Discrepancy Terbuka')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->numeric(decimalPlaces: 0)),
                TextColumn::make('invoice.payable_amount')
                    ->label('Payable Invoice')
                    ->money('IDR')
                    ->alignEnd()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('verified_payment_amount')
                    ->label('Sudah Dibayar')
                    ->state(fn (PurchaseOrder $record): float => self::verifiedPayment($record))
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('outstanding_amount')
                    ->label('Outstanding')
                    ->state(fn (PurchaseOrder $record): float => max(
                        0,
                        (float) ($record->invoice?->payable_amount ?? 0) - self::verifiedPayment($record),
                    ))
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(),
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
                    ->options(self::statusOptions())
                    ->multiple(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->groups([
                Group::make('kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
                Group::make('kitchen.organization.name')
                    ->label('Organisasi')
                    ->collapsible(),
                Group::make('supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('status')
                    ->label('Status PO')
                    ->getTitleFromRecordUsing(fn (PurchaseOrder $record): string => self::statusLabel($record->status))
                    ->collapsible(),
            ])
            ->defaultGroup('kitchen.name')
            ->defaultSort('order_date', 'desc')
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

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(PurchaseOrderStatus::cases())
            ->mapWithKeys(fn (PurchaseOrderStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    private static function verifiedPayment(PurchaseOrder $record): float
    {
        return (float) ($record->invoice?->payments
            ->where('status', PaymentStatus::Verified)
            ->sum('amount') ?? 0);
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
