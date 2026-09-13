<?php

namespace App\Filament\Admin\Resources\Units;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Concerns\AuthorizesMasterData;
use App\Filament\Admin\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UnitResource extends Resource
{
    use AuthorizesMasterData;

    protected static ?string $model = Unit::class;
    protected static ?string $navigationLabel = 'Satuan';
    protected static ?string $modelLabel = 'satuan';
    protected static ?string $pluralModelLabel = 'satuan';
    protected static string | UnitEnum | null $navigationGroup = 'Master & Organisasi';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Kode')->required()->maxLength(30)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nama')->required()->maxLength(100),
            TextInput::make('symbol')->label('Simbol')->required()->maxLength(20),
            TextInput::make('decimal_places')->label('Digit desimal')->numeric()->minValue(0)->maxValue(6)->default(2)->required(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('symbol')->label('Simbol'),
                TextColumn::make('decimal_places')->label('Desimal')->numeric()->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(static fn (Model $record): bool => ! $record->products()->exists()),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false)
            && ! $record->products()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ManageUnits::route('/')];
    }
}
