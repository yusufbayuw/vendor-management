<?php

namespace App\Filament\Supplier\Resources\Products;

use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\Products\Pages\ManageSupplierProducts;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use UnitEnum;

class SupplierProductResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;

    protected static ?string $navigationLabel = 'Katalog Produk';

    protected static ?string $modelLabel = 'produk supplier';

    protected static ?string $pluralModelLabel = 'katalog produk';

    protected static string|UnitEnum|null $navigationGroup = 'Perusahaan';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label('Supplier')
                ->options(static::supplierOptions())
                ->default(fn (): ?int => static::singleSupplierId())
                ->disabled(fn (): bool => static::singleSupplierId() !== null)
                ->dehydrated()
                ->required(),
            Select::make('product_id')
                ->label('Produk')
                ->options(Product::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            TextInput::make('supplier_product_code')->label('Kode Produk Supplier')->maxLength(100),
            TextInput::make('minimum_order_qty')->label('Minimum Order Qty')->numeric()->minValue(0),
            TextInput::make('maximum_order_qty')->label('Maximum Order Qty')->numeric()->minValue(0),
            TextInput::make('lead_time_days')->label('Lead Time (hari)')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('indicative_price')->label('Harga Indikatif')->numeric()->prefix('Rp')->minValue(0),
            Toggle::make('is_available')->label('Tersedia')->default(true),
            DatePicker::make('valid_from')->label('Berlaku Mulai')->native(false),
            DatePicker::make('valid_until')->label('Berlaku Sampai')->native(false)->afterOrEqual('valid_from'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Supplier')->searchable(),
            TextColumn::make('product.code')->label('Kode')->searchable(),
            TextColumn::make('product.name')->label('Produk')->searchable()->sortable(),
            TextColumn::make('minimum_order_qty')->label('MOQ')->numeric(decimalPlaces: 2),
            TextColumn::make('maximum_order_qty')->label('Maks. Qty')->numeric(decimalPlaces: 2),
            TextColumn::make('lead_time_days')->label('Lead Time')->suffix(' hari'),
            TextColumn::make('indicative_price')->label('Harga Indikatif')->money('IDR'),
            IconColumn::make('is_available')->label('Tersedia')->boolean(),
            TextColumn::make('valid_until')->label('Berlaku Sampai')->date('d/m/Y')->toggleable(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['supplier', 'product'])
            ->whereIn('supplier_id', static::supplierIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof SupplierProduct
            && in_array($record->supplier_id, static::supplierIds(), true)
            && static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()
            ->wherePivot('is_active', true)
            ->pluck('suppliers.id')
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];
    }

    private static function supplierOptions(): array
    {
        return Supplier::query()
            ->whereIn('id', static::supplierIds())
            ->orderBy('display_name')
            ->pluck('display_name', 'id')
            ->all();
    }

    private static function singleSupplierId(): ?int
    {
        $options = static::supplierOptions();

        return count($options) === 1 ? (int) array_key_first($options) : null;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierProducts::route('/')];
    }
}
