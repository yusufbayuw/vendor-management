<?php

namespace App\Filament\Admin\Resources\Users;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationLabel = 'Pengguna';
    protected static ?string $modelLabel = 'pengguna';
    protected static ?string $pluralModelLabel = 'pengguna';
    protected static string | UnitEnum | null $navigationGroup = 'Administrasi';
    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->required(static fn (string $operation): bool => $operation === 'create')
                ->dehydrated(static fn (?string $state): bool => filled($state))
                ->minLength(8),
            Select::make('roles')
                ->label('Role')
                ->relationship('roles', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('roles.name')->label('Role')->badge()->separator(', '),
                TextColumn::make('access_scopes_count')->label('Scope')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('roles')->withCount('accessScopes');
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
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
