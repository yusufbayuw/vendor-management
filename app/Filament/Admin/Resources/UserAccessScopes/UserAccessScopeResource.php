<?php

namespace App\Filament\Admin\Resources\UserAccessScopes;

use App\Enums\AccessScopeType;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\UserAccessScopes\Pages\ManageUserAccessScopes;
use App\Models\Organization;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\UserAccessScope;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UserAccessScopeResource extends Resource
{
    protected static ?string $model = UserAccessScope::class;

    protected static ?string $navigationLabel = 'Cakupan Akses';

    protected static ?string $modelLabel = 'cakupan akses';

    protected static ?string $pluralModelLabel = 'cakupan akses';

    protected static string|UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 91;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Pengguna')
                ->relationship('user', 'name')
                ->searchable(['name', 'email'])
                ->preload()
                ->required(),
            Select::make('scope_type')
                ->label('Jenis cakupan')
                ->options(collect(AccessScopeType::cases())->mapWithKeys(
                    static fn (AccessScopeType $type): array => [$type->value => $type->label()],
                )->all())
                ->live()
                ->required(),
            Select::make('scope_id')
                ->label('Cakupan data')
                ->options(static function (Get $get): array {
                    $type = AccessScopeType::tryFrom((string) $get('scope_type'));

                    return match ($type) {
                        AccessScopeType::Global => [0 => 'Seluruh data (Global)'],
                        AccessScopeType::Organization => Organization::query()->orderBy('name')->pluck('name', 'id')->all(),
                        AccessScopeType::SppgKitchen => SppgKitchen::query()->orderBy('name')->pluck('name', 'id')->all(),
                        AccessScopeType::Supplier => Supplier::query()->orderBy('display_name')->pluck('display_name', 'id')->all(),
                        default => [],
                    };
                })
                ->searchable()
                ->required(),
            Toggle::make('is_primary')->label('Cakupan utama')->default(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Pengguna')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('scope_type')
                    ->label('Jenis')
                    ->formatStateUsing(static fn ($state): string => $state instanceof AccessScopeType ? $state->label() : (AccessScopeType::tryFrom((string) $state)?->label() ?? (string) $state)),
                TextColumn::make('scope_id')
                    ->label('Cakupan')
                    ->formatStateUsing(static fn ($state, UserAccessScope $record): string => static::resolveScopeLabel($record)),
                IconColumn::make('is_primary')->label('Utama')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function resolveScopeLabel(UserAccessScope $record): string
    {
        return match ($record->scope_type) {
            AccessScopeType::Global => 'Seluruh data',
            AccessScopeType::Organization => Organization::query()->find($record->scope_id)?->name ?? '#'.$record->scope_id,
            AccessScopeType::SppgKitchen => SppgKitchen::query()->find($record->scope_id)?->name ?? '#'.$record->scope_id,
            AccessScopeType::Supplier => Supplier::query()->find($record->scope_id)?->display_name ?? '#'.$record->scope_id,
        };
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::GovernanceManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageUserAccessScopes::route('/')];
    }
}
