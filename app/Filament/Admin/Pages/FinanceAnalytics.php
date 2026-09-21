<?php

namespace App\Filament\Admin\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\SystemPermission;
use App\Filament\Exports\FinanceAnalyticsExporter;
use App\Models\Invoice;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\AnalyticsPeriodDefaults;
use App\Services\Analytics\FinanceAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Summarizers\Sum;
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

class FinanceAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Finance';

    protected static ?string $title = 'Finance Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 70;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $slug = 'analytics/finance';

    protected string $view = 'filament.admin.pages.finance-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::ReportsView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Analitik invoice-to-payment: payable, pembayaran terverifikasi, outstanding, aging, overdue, dan lead time approval ke pembayaran.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(FinanceAnalyticsService::class)->query($user))
            ->heading('Invoice & Settlement Analytics')
            ->description('Outstanding selalu dihitung dari payable dikurangi pembayaran berstatus verified. Invoice rejected/cancelled diklasifikasikan non-payable.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(FinanceAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('invoice_date')
                    ->label('Tanggal Invoice')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('kitchen.name')
                    ->label('SPPG')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.display_name')
                    ->label('Supplier')
                    ->formatStateUsing(fn (?string $state, Invoice $record): string => $state ?: ($record->supplier?->legal_name ?? '-'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Nomor Invoice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('payable_amount')
                    ->label('Payable')
                    ->money('IDR')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('IDR')),
                TextColumn::make('verified_paid_amount')
                    ->label('Terverifikasi Dibayar')
                    ->state(fn (Invoice $record): float => $this->metrics()->verifiedPaid($record))
                    ->money('IDR')
                    ->alignEnd(),
                TextColumn::make('outstanding_amount')
                    ->label('Outstanding')
                    ->state(fn (Invoice $record): float => $this->metrics()->outstanding($record))
                    ->money('IDR')
                    ->alignEnd(),
                TextColumn::make('aging_bucket')
                    ->label('Aging')
                    ->state(fn (Invoice $record): string => $this->metrics()->agingBucket($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Paid' => 'success',
                        'Current' => 'info',
                        'Non-payable' => 'gray',
                        '1–7 hari' => 'warning',
                        '8–14 hari', '15–30 hari', '>30 hari' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('overdue_days')
                    ->label('Overdue')
                    ->state(fn (Invoice $record): int => $this->metrics()->overdueDays($record))
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '-' : number_format($state).' hari')
                    ->alignEnd(),
                TextColumn::make('payment_lead_days')
                    ->label('Approval → Payment')
                    ->state(fn (Invoice $record): ?float => $this->metrics()->paymentLeadDays($record))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '-' : number_format($state, 1, ',', '.').' hari')
                    ->alignEnd(),
                TextColumn::make('purchaseOrder.number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('po_amount')
                    ->label('Nilai PO')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('adjustment_amount')
                    ->label('Adjustment')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('withholding_tax_amount')
                    ->label('Pajak Potong')
                    ->money('IDR')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->invoices(auth()->user())['from']),
                        DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->default(fn (): string => app(AnalyticsPeriodDefaults::class)->invoices(auth()->user())['to']),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('invoice_date', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('invoice_date', '<=', $date),
                        )),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->multiple(),
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
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereDate('due_date', '<', today())
                            ->whereNotIn('status', [
                                InvoiceStatus::Paid->value,
                                InvoiceStatus::Rejected->value,
                                InvoiceStatus::Cancelled->value,
                            ]),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                            $query
                                ->whereNull('due_date')
                                ->orWhereDate('due_date', '>=', today())
                                ->orWhereIn('status', [
                                    InvoiceStatus::Paid->value,
                                    InvoiceStatus::Rejected->value,
                                    InvoiceStatus::Cancelled->value,
                                ]);
                        }),
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(5)
            ->groups([
                Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (Invoice $record): string => self::statusLabel($record->status))
                    ->collapsible(),
                Group::make('supplier.display_name')
                    ->label('Supplier')
                    ->collapsible(),
                Group::make('kitchen.name')
                    ->label('SPPG')
                    ->collapsible(),
            ])
            ->defaultGroup('status')
            ->defaultSort('invoice_date', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): FinanceAnalyticsService
    {
        return app(FinanceAnalyticsService::class);
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
            ->whereHas('invoices', fn (Builder $query): Builder => $query->whereIn('sppg_kitchen_id', $kitchenIds))
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
        return collect(InvoiceStatus::cases())
            ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom((string) $state);

        return match ($status) {
            InvoiceStatus::Draft => 'Draft',
            InvoiceStatus::Submitted => 'Submitted',
            InvoiceStatus::UnderReview => 'Under Review',
            InvoiceStatus::Approved => 'Approved',
            InvoiceStatus::PartiallyPaid => 'Partially Paid',
            InvoiceStatus::Paid => 'Paid',
            InvoiceStatus::Rejected => 'Rejected',
            InvoiceStatus::Cancelled => 'Cancelled',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom((string) $state);

        return match ($status) {
            InvoiceStatus::Paid => 'success',
            InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid => 'warning',
            InvoiceStatus::Rejected, InvoiceStatus::Cancelled => 'danger',
            InvoiceStatus::Submitted, InvoiceStatus::UnderReview => 'info',
            default => 'gray',
        };
    }
}
