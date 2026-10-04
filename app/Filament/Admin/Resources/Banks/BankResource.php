<?php

namespace App\Filament\Admin\Resources\Banks;

use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Banks\Pages\ManageBanks;
use App\Filament\Admin\Resources\Concerns\AuthorizesMasterData;
use App\Models\Bank;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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

class BankResource extends Resource
{
    use AuthorizesMasterData;

    protected static ?string $model = Bank::class;

    protected static ?string $navigationLabel = 'Daftar Bank';

    protected static ?string $modelLabel = 'bank';

    protected static ?string $pluralModelLabel = 'daftar bank';

    protected static string|UnitEnum|null $navigationGroup = 'Master & Organisasi';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama bank')
                ->helperText('Gunakan nama yang mudah dikenali supplier, misalnya BRI, BCA, Bank Jago, atau Bank BJB.')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Kode bank')
                ->helperText('Kode transfer antarbank 3 digit.')
                ->required()
                ->minLength(3)
                ->maxLength(3)
                ->regex('/^\d{3}$/')
                ->unique(ignoreRecord: true),
            TextInput::make('sort_order')
                ->label('Urutan tampil')
                ->helperText('Angka kecil tampil lebih dahulu pada pilihan bank.')
                ->numeric()
                ->minValue(1)
                ->default(999)
                ->required(),
            Toggle::make('is_active')
                ->label('Tampilkan kepada supplier')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama Bank')->searchable()->sortable(),
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('supplier_bank_accounts_count')->label('Rekening Supplier')->sortable(),
                TextColumn::make('sort_order')->label('Urutan')->sortable()->toggleable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(
                    static fn (Bank $record): bool => ! $record->supplierBankAccounts()->exists(),
                ),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('supplierBankAccounts');
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Bank
            && (auth()->user()?->can(SystemPermission::MasterDataManage->value) ?? false)
            && ! $record->supplierBankAccounts()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ManageBanks::route('/')];
    }
}
