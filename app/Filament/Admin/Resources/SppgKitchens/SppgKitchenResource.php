<?php

namespace App\Filament\Admin\Resources\SppgKitchens;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\SppgKitchens\Pages\ManageSppgKitchens;
use App\Models\Organization;
use App\Models\SppgKitchen;
use App\Services\Access\UserAccessService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SppgKitchenResource extends Resource
{
    protected static ?string $model = SppgKitchen::class;
    protected static ?string $navigationLabel = 'Dapur SPPG';
    protected static ?string $modelLabel = 'dapur SPPG';
    protected static ?string $pluralModelLabel = 'dapur SPPG';
    protected static string | UnitEnum | null $navigationGroup = 'Master & Organisasi';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->label('Organisasi')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user) {
                        return [];
                    }

                    $query = Organization::query()->where('is_active', true)->orderBy('name');

                    return app(UserAccessService::class)
                        ->applyOrganizationScope($query, $user)
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->required(),
            TextInput::make('code')->label('Kode')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nama SPPG')->required()->maxLength(255),
            Textarea::make('address')->label('Alamat')->rows(3)->columnSpanFull(),
            TextInput::make('province_code')->label('Kode Provinsi')->maxLength(20),
            TextInput::make('regency_code')->label('Kode Kabupaten/Kota')->maxLength(20),
            TextInput::make('district_code')->label('Kode Kecamatan')->maxLength(20),
            TextInput::make('village_code')->label('Kode Desa/Kelurahan')->maxLength(20),
            TextInput::make('postal_code')->label('Kode Pos')->maxLength(10),
            TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('SPPG')->searchable()->sortable(),
                TextColumn::make('organization.name')->label('Organisasi')->searchable()->sortable(),
                TextColumn::make('phone')->label('Telepon')->toggleable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('organization');
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applyKitchenScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::KitchenView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(SystemPermission::KitchenManage->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can(SystemPermission::KitchenManage->value)
            && app(UserAccessService::class)->canAccessKitchen($user, (int) $record->getKey());
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record)
            && ! $record->purchaseRequests()->exists()
            && ! $record->purchaseOrders()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSppgKitchens::route('/')];
    }
}
