<?php

namespace App\Filament\Admin\Resources\LegacyImportBatches;

use App\Enums\LegacyImportType;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\LegacyImportBatches\Pages\CreateLegacyImportBatch;
use App\Filament\Admin\Resources\LegacyImportBatches\Pages\ListLegacyImportBatches;
use App\Jobs\ProcessLegacyImportBatch;
use App\Models\LegacyImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LegacyImportBatchResource extends Resource
{
    protected static ?string $model = LegacyImportBatch::class;

    protected static ?string $navigationLabel = 'Import Data Lama';

    protected static ?string $modelLabel = 'batch import data lama';

    protected static ?string $pluralModelLabel = 'import data lama';

    protected static string|UnitEnum|null $navigationGroup = 'Administrasi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 96;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->label('Organisasi')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return [];
                    }

                    return Organization::query()
                        ->whereIn('id', app(UserAccessService::class)->manageableOrganizationIds($user))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required(),
            Select::make('import_type')
                ->label('Jenis data')
                ->options(collect(LegacyImportType::cases())->mapWithKeys(
                    static fn (LegacyImportType $type): array => [$type->value => $type->label()],
                )->all())
                ->live()
                ->required()
                ->helperText(static function (Get $get): string {
                    $type = LegacyImportType::tryFrom((string) $get('import_type'));

                    return $type === null
                        ? 'Pilih jenis data untuk melihat kolom wajib.'
                        : 'Kolom wajib: '.implode(', ', $type->requiredColumns()).'. Baris dapat memuat kolom tambahan sesuai data yang tersedia.';
                }),
            FileUpload::make('file_path')
                ->label('File CSV / XLSX')
                ->disk(VendorFileStorage::DISK)
                ->directory('legacy-imports')
                ->visibility('private')
                ->preserveFilenames()
                ->acceptedFileTypes([
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->maxSize(20480)
                ->required()
                ->helperText('Baris pertama harus berisi nama kolom. Import tidak menjalankan ulang approval/notifikasi workflow.'),
            Toggle::make('dry_run')
                ->label('Dry-run: simulasi tanpa menyimpan perubahan')
                ->default(true)
                ->helperText('Direkomendasikan. Setelah simulasi berhasil, klik Jalankan Import pada baris hasil preview.'),
            Textarea::make('notes')
                ->label('Catatan migrasi')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Batch')->formatStateUsing(static fn ($state): string => '#'.$state)->sortable(),
                TextColumn::make('organization.name')->label('Organisasi')->searchable()->sortable(),
                TextColumn::make('import_type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state instanceof LegacyImportType ? $state->label() : (LegacyImportType::tryFrom((string) $state)?->label() ?? (string) $state)),
                TextColumn::make('original_filename')->label('File')->searchable()->wrap(),
                TextColumn::make('dry_run')->label('Mode')
                    ->formatStateUsing(static fn ($state): string => $state ? 'Simulasi' : 'Import')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'preview_completed' => 'success',
                        'queued' => 'info',
                        'completed_with_errors' => 'warning',
                        'failed' => 'danger',
                        'processing' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')->label('Baris')->numeric(),
                TextColumn::make('imported_rows')->label('Berhasil')->numeric(),
                TextColumn::make('failed_rows')->label('Gagal')->numeric(),
                TextColumn::make('importer.name')->label('Diimport oleh')->placeholder('-'),
                TextColumn::make('imported_at')->label('Diproses')->dateTime('d/m/Y H:i')->placeholder('-'),
            ])
            ->recordActions([
                Action::make('runPreview')
                    ->label('Jalankan Import')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Import nyata akan membuat/memperbarui record sesuai file preview. Pastikan hasil simulasi tidak memiliki error.')
                    ->visible(static fn (LegacyImportBatch $record): bool => $record->dry_run
                        && $record->status === 'preview_completed'
                        && $record->failed_rows === 0
                        && ! LegacyImportBatch::query()->where('source_preview_batch_id', $record->getKey())->exists())
                    ->action(static function (LegacyImportBatch $record): void {
                        $actor = auth()->user();
                        $access = app(UserAccessService::class);

                        if (! $actor instanceof User || ! $actor->is_active
                            || ! $actor->can(SystemPermission::LegacyImportManage->value)
                            || ! $access->canManageOrganization($actor, (int) $record->organization_id)) {
                            throw new DomainException('Tidak berwenang menjalankan import organisasi ini.');
                        }

                        $batch = LegacyImportBatch::query()->create([
                            'organization_id' => $record->organization_id,
                            'import_type' => $record->import_type,
                            'original_filename' => $record->original_filename,
                            'file_path' => $record->file_path,
                            'dry_run' => false,
                            'source_preview_batch_id' => $record->getKey(),
                            'status' => 'queued',
                            'imported_by' => $actor->getKey(),
                            'notes' => $record->notes,
                        ]);

                        ProcessLegacyImportBatch::dispatch($batch->getKey())->afterCommit();
                        Notification::make()->success()
                            ->title('Import nyata dijadwalkan')
                            ->body('Batch #'.$batch->getKey().' diproses oleh queue worker.')
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['organization', 'importer']);
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $organizationIds = app(UserAccessService::class)
            ->applyOrganizationScope(Organization::query(), $user)
            ->pluck('id');

        return $query->whereIn('organization_id', $organizationIds);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::LegacyImportManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegacyImportBatches::route('/'),
            'create' => CreateLegacyImportBatch::route('/create'),
        ];
    }
}
