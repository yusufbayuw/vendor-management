<?php

namespace App\Filament\Admin\Resources\LegacyImportBatches;

use App\Enums\LegacyImportType;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\LegacyImportBatches\Pages\CreateLegacyImportBatch;
use App\Filament\Admin\Resources\LegacyImportBatches\Pages\ListLegacyImportBatches;
use App\Models\LegacyImportBatch;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

                    return app(UserAccessService::class)
                        ->applyOrganizationScope(Organization::query()->orderBy('name'), $user)
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
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'completed' => 'success',
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
