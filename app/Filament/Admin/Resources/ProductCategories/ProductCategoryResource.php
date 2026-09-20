<?php

namespace App\Filament\Admin\Resources\ProductCategories;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Concerns\AuthorizesMasterData;
use App\Filament\Admin\Resources\ProductCategories\Pages\ManageProductCategories;
use App\Filament\Admin\Support\MasterDataDuplicateGuard;
use App\Filament\Admin\Support\MasterDataOptionFactory;
use App\Models\ProductCategory;
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

class ProductCategoryResource extends Resource
{
    use AuthorizesMasterData;

    protected static ?string $model = ProductCategory::class;

    protected static ?string $navigationLabel = 'Kategori Produk';

    protected static ?string $modelLabel = 'kategori produk';

    protected static ?string $pluralModelLabel = 'kategori produk';

    protected static string|UnitEnum|null $navigationGroup = 'Master & Organisasi';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            MasterDataOptionFactory::category(
                Select::make('parent_id')
                    ->label('Kategori induk')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
            ),
            TextInput::make('code')->label('Kode')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->helperText(static fn (?string $state, ?ProductCategory $record): ?string => MasterDataDuplicateGuard::hint(
                    ProductCategory::class,
                    $state,
                    $record?->getKey(),
                )),
            Textarea::make('description')->label('Deskripsi')->rows(3)->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Induk')->placeholder('-'),
                TextColumn::make('products_count')->label('Produk')->sortable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(static fn (Model $record): bool => ! $record->products()->exists() && ! $record->children()->exists()),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('products')->with('parent');
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false)
            && ! $record->products()->exists()
            && ! $record->children()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ManageProductCategories::route('/')];
    }
}
