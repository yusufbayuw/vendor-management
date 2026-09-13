<?php

namespace App\Filament\Supplier\Resources\BankAccounts;

use App\Enums\SystemPermission;
use App\Enums\VerificationStatus;
use App\Filament\Supplier\Resources\BankAccounts\Pages\ManageSupplierBankAccounts;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
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

class SupplierBankAccountResource extends Resource
{
    protected static ?string $model = SupplierBankAccount::class;
    protected static ?string $navigationLabel = 'Rekening Bank';
    protected static string | UnitEnum | null $navigationGroup = 'Perusahaan';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->label('Supplier')->options(static::supplierOptions())->required(),
            TextInput::make('bank_name')->label('Nama Bank')->required()->maxLength(255),
            TextInput::make('bank_code')->label('Kode Bank')->maxLength(30),
            TextInput::make('account_number')->label('Nomor Rekening')->required()->maxLength(100),
            TextInput::make('account_holder')->label('Nama Pemilik Rekening')->required()->maxLength(255),
            Toggle::make('is_primary')->label('Rekening Utama'),
            Hidden::make('verification_status')
                ->default(VerificationStatus::Pending->value)
                ->dehydrateStateUsing(fn () => VerificationStatus::Pending->value),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Supplier'),
            TextColumn::make('bank_name')->label('Bank')->searchable(),
            TextColumn::make('account_number')->label('Nomor Rekening')->searchable(),
            TextColumn::make('account_holder')->label('Atas Nama'),
            IconColumn::make('is_primary')->label('Utama')->boolean(),
            TextColumn::make('verification_status')->label('Verifikasi')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof VerificationStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->visible(fn (SupplierBankAccount $record) => in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('supplier')->whereIn('supplier_id', static::supplierIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierProfileManage->value) ?? false;
    }

    public static function canCreate(): bool { return static::canViewAny(); }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof SupplierBankAccount && in_array($record->supplier_id, static::supplierIds(), true) && static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof SupplierBankAccount
            && in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)
            && static::canEdit($record);
    }

    public static function canDeleteAny(): bool { return false; }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function supplierOptions(): array
    {
        return Supplier::query()->whereIn('id', static::supplierIds())->pluck('display_name', 'id')->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierBankAccounts::route('/')];
    }
}
