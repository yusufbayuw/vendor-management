<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Filament\Exports\AuditAnalyticsExporter;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Analytics\AuditAnalyticsService;
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

class AuditAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Audit Trail';

    protected static ?string $title = 'Audit Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 100;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $slug = 'analytics/audit';

    protected string $view = 'filament.admin.pages.audit-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(SystemPermission::AuditView->value) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Audit trail perubahan data berdasarkan scope akses. Payload sensitif tidak ditampilkan sebagai kolom utama dan identifier finansial telah dimask saat dicatat.';
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can(SystemPermission::AuditView->value), 403);

        return $table
            ->query($this->metrics()->query($user))
            ->heading('Audit Trail')
            ->description('Gunakan filter actor, event, dan tipe objek untuk investigasi. IP dan user-agent disembunyikan secara default karena lebih sensitif.')
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export CSV / XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exporter(AuditAnalyticsExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ])
                    ->maxRows(50_000),
            ])
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->placeholder('System')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::eventLabel($state))
                    ->color(fn (string $state): string => self::eventColor($state)),
                TextColumn::make('auditable_type')
                    ->label('Tipe Objek')
                    ->formatStateUsing(fn (string $state): string => $this->metrics()->modelLabel($state))
                    ->badge(),
                TextColumn::make('object_label')
                    ->label('Objek')
                    ->state(fn (AuditLog $record): string => $this->metrics()->objectLabel($record))
                    ->wrap(),
                TextColumn::make('changed_fields_count')
                    ->label('Field')
                    ->state(fn (AuditLog $record): int => $this->metrics()->changedFieldCount($record))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('changed_fields')
                    ->label('Field Berubah')
                    ->state(fn (AuditLog $record): string => $this->metrics()->changedFieldsLabel($record))
                    ->wrap(),
                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')
                    ->label('User Agent')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('auditable_id')
                    ->label('Object ID')
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                        )),
                SelectFilter::make('event')
                    ->label('Event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ])
                    ->multiple(),
                SelectFilter::make('auditable_type')
                    ->label('Tipe Objek')
                    ->options(fn (): array => $this->typeOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('actor_id')
                    ->label('Actor')
                    ->options(fn (): array => $this->actorOptions())
                    ->searchable()
                    ->preload(),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->groups([
                Group::make('event')
                    ->label('Event')
                    ->getTitleFromRecordUsing(fn (AuditLog $record): string => self::eventLabel($record->event))
                    ->collapsible(),
                Group::make('auditable_type')
                    ->label('Tipe Objek')
                    ->getTitleFromRecordUsing(fn (AuditLog $record): string => $this->metrics()->modelLabel($record->auditable_type))
                    ->collapsible(),
                Group::make('actor.name')
                    ->label('Actor')
                    ->collapsible(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginationPageOptions([25, 50, 100]);
    }

    private function metrics(): AuditAnalyticsService
    {
        return app(AuditAnalyticsService::class);
    }

    /** @return array<string, string> */
    private function typeOptions(): array
    {
        $user = auth()->user();

        return $user === null ? [] : $this->metrics()->typeOptions($user);
    }

    /** @return array<int, string> */
    private function actorOptions(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $actorIds = $this->metrics()
            ->query($user)
            ->reorder()
            ->whereNotNull('actor_id')
            ->select('actor_id')
            ->distinct()
            ->pluck('actor_id');

        return User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function eventLabel(string $state): string
    {
        return match ($state) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            default => str($state)->headline()->toString(),
        };
    }

    private static function eventColor(string $state): string
    {
        return match ($state) {
            'created' => 'success',
            'updated' => 'warning',
            'deleted' => 'danger',
            default => 'gray',
        };
    }
}
