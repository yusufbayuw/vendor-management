<?php

namespace App\Filament\Admin\Resources\Users;

use App\Actions\Auth\ManuallyVerifyPhoneAction;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'pengguna';

    protected static ?string $pluralModelLabel = 'pengguna';

    protected static string|UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),
            TextInput::make('username')
                ->label('Username')
                ->helperText('Diawali huruf. Boleh memakai huruf, angka, titik, garis bawah, dan strip.')
                ->required()
                ->maxLength(50)
                ->regex('/^[A-Za-z][A-Za-z0-9._-]{2,49}$/')
                ->unique(ignoreRecord: true),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('phone')
                ->label('Nomor HP')
                ->tel()
                ->maxLength(30)
                ->dehydrateStateUsing(static fn (?string $state): ?string => LoginIdentifier::normalizePhone($state))
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
                TextColumn::make('username')->label('Username')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('phone')->label('Nomor HP')->searchable()->placeholder('-'),
                TextColumn::make('phone_verified_at')
                    ->label('Verifikasi HP')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state ? 'Terverifikasi' : 'Belum')
                    ->color(static fn ($state): string => $state ? 'success' : 'warning'),
                TextColumn::make('roles.name')->label('Role')->badge()->separator(', '),
                TextColumn::make('access_scopes_count')->label('Scope')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('verifyPhoneManually')
                    ->label('Verifikasi HP Manual')
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->visible(static fn (User $record): bool => filled($record->phone) && $record->phone_verified_at === null)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan verifikasi manual')
                            ->helperText('Gunakan hanya saat verifikasi OTP tidak dapat dilakukan. Tindakan ini masuk audit log.')
                            ->required()
                            ->rows(3),
                    ])
                    ->requiresConfirmation()
                    ->action(static function (User $record, array $data): void {
                        try {
                            app(ManuallyVerifyPhoneAction::class)->execute(
                                $record,
                                auth()->user(),
                                (string) $data['reason'],
                            );

                            Notification::make()->success()->title('Nomor HP ditandai terverifikasi.')->send();
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
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
