<?php

namespace App\Filament\Admin\Resources\PurchaseRequestTemplates;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\PurchaseRequestTemplates\Pages\ManagePurchaseRequestTemplates;
use App\Filament\Admin\Support\MasterDataOptionFactory;
use App\Models\Product;
use App\Models\PurchaseRequestTemplate;
use App\Models\SppgKitchen;
use App\Models\Unit;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseRequestTemplateResource extends Resource
{
    protected static ?string $model = PurchaseRequestTemplate::class;

    protected static ?string $navigationLabel = 'Template PR';

    protected static ?string $modelLabel = 'template PR';

    protected static ?string $pluralModelLabel = 'template PR';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('created_by')
                ->default(static fn (): ?int => auth()->id()),
            Select::make('sppg_kitchen_id')
                ->label('Dapur SPPG')
                ->options(static function (): array {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return [];
                    }

                    return app(UserAccessService::class)
                        ->applyKitchenScope(SppgKitchen::query()->where('is_active', true)->orderBy('name'), $user)
                        ->pluck('name', 'id')
                        ->all();
                })
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')
                ->label('Nama template')
                ->required()
                ->maxLength(150),
            Textarea::make('description')
                ->label('Deskripsi kebutuhan')
                ->rows(2)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Catatan')
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Repeater::make('items')
                ->label('Item template')
                ->relationship()
                ->schema([
                    MasterDataOptionFactory::product(
                        Select::make('product_id')
                            ->label('Produk')
                            ->options(static fn (): array => Product::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload(),
                    )
                        ->live()
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            $set(
                                'unit_id',
                                filled($state) ? Product::query()->whereKey($state)->value('default_unit_id') : null,
                            );
                        })
                        ->required(),
                    MasterDataOptionFactory::unit(
                        Select::make('unit_id')
                            ->label('Satuan')
                            ->options(static fn (): array => Unit::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ),
                    TextInput::make('requested_qty')
                        ->label('Jumlah standar')
                        ->numeric()
                        ->minValue(0.0001)
                        ->required(),
                    TextInput::make('estimated_unit_price')
                        ->label('Estimasi harga satuan')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0),
                    TextInput::make('description')
                        ->label('Deskripsi item')
                        ->maxLength(255),
                    Textarea::make('quality_specification')
                        ->label('Spesifikasi kualitas')
                        ->rows(2)
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Catatan item')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->reorderable()
                ->orderColumn('sort_order')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Template')->searchable()->sortable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable()->sortable(),
                TextColumn::make('items_count')->label('Item')->sortable(),
                TextColumn::make('creator.name')->label('Dibuat oleh')->toggleable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['kitchen', 'creator'])
            ->withCount('items');
        $user = auth()->user();

        return $user instanceof User
            ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::PurchaseRequestSubmit->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $record instanceof PurchaseRequestTemplate
            && $user->can(SystemPermission::PurchaseRequestSubmit->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseRequestTemplates::route('/'),
        ];
    }
}
