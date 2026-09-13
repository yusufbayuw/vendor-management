<?php

namespace App\Filament\Admin\Resources\Organizations;

use App\Enums\OperationalProfile;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Organizations\Pages\ManageOrganizations;
use App\Models\Organization;
use App\Services\Access\UserAccessService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationLabel = 'Organisasi';

    protected static ?string $modelLabel = 'organisasi';

    protected static ?string $pluralModelLabel = 'organisasi';

    protected static ?string $navigationGroup = 'Master & Organisasi';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Kode')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true),
            TextInput::make('name')
                ->label('Nama organisasi')
                ->required()
                ->maxLength(255),
            Select::make('operational_profile')
                ->label('Profil operasional')
                ->options(collect(OperationalProfile::cases())->mapWithKeys(
                    static fn (OperationalProfile $profile): array => [$profile->value => $profile->label()],
                )->all())
                ->required()
                ->default(OperationalProfile::Standard->value),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('operational_profile')
                    ->label('Profil')
                    ->formatStateUsing(static fn ($state): string => $state instanceof OperationalProfile ? $state->label() : (string) $state),
                TextColumn::make('kitchens_count')->counts('kitchens')->label('Jumlah SPPG')->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('kitchens');
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyOrganizationScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::OrganizationView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(SystemPermission::OrganizationManage->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->can(SystemPermission::OrganizationManage->value) ?? false)
            && app(UserAccessService::class)->canAccessOrganization(auth()->user(), (int) $record->getKey());
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record) && ! $record->kitchens()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOrganizations::route('/'),
        ];
    }
}
