<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\SystemPermission;
use App\Filament\Exports\GovernanceAnalyticsExporter;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Services\Access\UserAccessService;
use App\Services\Analytics\GovernanceAnalyticsService;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
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

class GovernanceAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Governance';

    protected static ?string $title = 'Governance Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $slug = 'analytics/governance';

    protected string $view = 'filament.admin.pages.governance-analytics';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can(SystemPermission::ReportsView->value)
            || $user->can(SystemPermission::AuditView->value)
        );
    }

    public function getSubheading(): ?string
    {
        return 'Analitik approval dan governance: decision time, jumlah approver, self-approval, override, serta pola approval/rejection per proses.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return $table
            ->query(app(GovernanceAnalyticsService::class)->query($user))
            ->heading('Approval & Governance Performance')
            ->description('Snapshot governance pada approval request dipertahankan sebagai source of truth historis meskipun policy organisasi berubah kemudian.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(GovernanceAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('requested_at')
                    ->label('Diminta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('organization.name')
                    ->label('Organisasi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('process')
                    ->label('Proses')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::processLabel($state)),
                TextColumn::make('approvable_label')
                    ->label('Objek Approval')
                    ->state(fn (ApprovalRequest $record): string => $this->metrics()->approvableLabel($record))
                    ->wrap(),
                TextColumn::make('requester.name')
                    ->label('Requester')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Nilai')
                    ->money('IDR')
                    ->placeholder('-')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('required_approvers')
                    ->label('Required')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('approval_progress')
                    ->label('Progress')
                    ->state(fn (ApprovalRequest $record): string => $this->metrics()->approvalProgress($record))
                    ->alignCenter(),
                IconColumn::make('self_approval_allowed')
                    ->label('Self Allowed')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('had_self_approval')
                    ->label('Self Approval')
                    ->state(fn (ApprovalRequest $record): bool => $this->metrics()->hadSelfApproval($record))
                    ->boolean()
                    ->alignCenter(),
                IconColumn::make('had_override')
                    ->label('Override')
                    ->state(fn (ApprovalRequest $record): bool => $this->metrics()->hadOverride($record))
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('decision_hours')
                    ->label('Decision Time')
                    ->state(fn (ApprovalRequest $record): ?float => $this->metrics()->decisionHours($record))
                    ->formatStateUsing(fn (?float $state): string => $this->metrics()->formatHours($state))
                    ->alignEnd(),
                TextColumn::make('actions_count')
                    ->label('Actions')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Periode Request')
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
                            fn (Builder $query, string $date): Builder => $query->whereDate('requested_at', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('requested_at', '<=', $date),
                        )),
                SelectFilter::make('organization_id')
                    ->label('Organisasi')
                    ->options(fn (): array => $this->organizationOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('process')
                    ->label('Proses')
                    ->options(self::processOptions())
                    ->multiple(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->multiple(),
                TernaryFilter::make('self_approval')
                    ->label('Terjadi Self Approval')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'actions',
                            fn (Builder $actionQuery): Builder => $actionQuery->where('is_self_approval', true),
                        ),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave(
                            'actions',
                            fn (Builder $actionQuery): Builder => $actionQuery->where('is_self_approval', true),
                        ),
                    ),
                TernaryFilter::make('override')
                    ->label('Terjadi Override')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'actions',
                            fn (Builder $actionQuery): Builder => $actionQuery
                                ->whereNotNull('override_reason')
                                ->where('override_reason', '!=', ''),
                        ),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave(
                            'actions',
                            fn (Builder $actionQuery): Builder => $actionQuery
                                ->whereNotNull('override_reason')
                                ->where('override_reason', '!=', ''),
                        ),
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(6)
            ->groups([
                Group::make('process')
                    ->label('Proses')
                    ->getTitleFromRecordUsing(fn (ApprovalRequest $record): string => self::processLabel($record->process))
                    ->collapsible(),
                Group::make('organization.name')
                    ->label('Organisasi')
                    ->collapsible(),
                Group::make('status')
                    ->label('Status')
                    ->getTitleFromRecordUsing(fn (ApprovalRequest $record): string => self::statusLabel($record->status))
                    ->collapsible(),
            ])
            ->defaultGroup('process')
            ->defaultSort('requested_at', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): GovernanceAnalyticsService
    {
        return app(GovernanceAnalyticsService::class);
    }

    /** @return array<int, string> */
    private function organizationOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return app(UserAccessService::class)
            ->applyOrganizationScope(Organization::query(), $user)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private static function processOptions(): array
    {
        return collect(GovernanceProcess::cases())
            ->mapWithKeys(fn (GovernanceProcess $process): array => [$process->value => $process->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ApprovalStatus::cases())
            ->mapWithKeys(fn (ApprovalStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    private static function processLabel($state): string
    {
        $process = $state instanceof GovernanceProcess ? $state : GovernanceProcess::tryFrom((string) $state);

        return $process?->label() ?? (string) $state;
    }

    private static function statusLabel($state): string
    {
        $status = $state instanceof ApprovalStatus ? $state : ApprovalStatus::tryFrom((string) $state);

        return match ($status) {
            ApprovalStatus::Pending => 'Pending',
            ApprovalStatus::Approved => 'Approved',
            ApprovalStatus::Rejected => 'Rejected',
            ApprovalStatus::Cancelled => 'Cancelled',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof ApprovalStatus ? $state : ApprovalStatus::tryFrom((string) $state);

        return match ($status) {
            ApprovalStatus::Approved => 'success',
            ApprovalStatus::Pending => 'warning',
            ApprovalStatus::Rejected => 'danger',
            ApprovalStatus::Cancelled => 'gray',
            default => 'gray',
        };
    }
}
