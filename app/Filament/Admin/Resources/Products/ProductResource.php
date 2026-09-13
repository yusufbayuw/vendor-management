<?php

namespace App\Filament\Admin\Resources\Products;

use App\Filament\Admin\Resources\Concerns\AuthorizesMasterData;
use App\Filament\Admin\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
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
use UnitEnum;

class ProductResource extends Resource
{
    use AuthorizesMasterData;

    protected static ?string $model = Product::class;

    protected static ?string $navigationLabel = 'Produk';

    protected static ?string $modelLabel = 'produk';

    protected static ?string $pluralModelLabel = 'produk';

    protected static string|UnitEnum|null $navigationGroup = 'Master & Organisasi';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Kode')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nama produk')->required()->maxLength(255),
            Select::make('category_id')
                ->label('Kategori')
                ->relationship('category', 'name', modifyQueryUsing: static fn ($query) => $query->where('is_active', true)->orderBy('name'))
                ->searchable()
                ->preload()
                ->required(),
            Select::make('default_unit_id')
                ->label('Satuan default')
                ->relationship('defaultUnit', 'name', modifyQueryUsing: static fn ($query) => $query->where('is_active', true)->orderBy('name'))
                ->searchable()
                ->preload()
                ->required(),
            Textarea::make('description')->label('Deskripsi / spesifikasi umum')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Produk')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Kategori')->sortable(),
                TextColumn::make('defaultUnit.symbol')->label('Satuan'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'defaultUnit']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
