<?php

namespace App\Filament\Supplier\Resources\BankAccounts;

use App\Enums\SystemPermission;
use App\Enums\VerificationStatus;
use App\Filament\Supplier\Resources\BankAccounts\Pages\ManageSupplierBankAccounts;
use App\Models\Bank;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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

    protected static ?string $navigationLabel = 'Rekening Pembayaran';

    protected static ?string $modelLabel = 'rekening pembayaran';

    protected static ?string $pluralModelLabel = 'rekening pembayaran';

    protected static string|UnitEnum|null $navigationGroup = 'Usaha Saya';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label('Usaha')
                ->options(static::supplierOptions())
                ->default(fn (): ?int => static::singleSupplierId())
                ->disabled(fn (): bool => static::singleSupplierId() !== null)
                ->dehydrated()
                ->required(),
            Select::make('bank_id')
                ->label('Pilih bank')
                ->placeholder('Ketik atau pilih nama bank')
                ->helperText('Kode bank diisi otomatis. Anda tidak perlu mencarinya sendiri.')
                ->options(fn (): array => Bank::activeOptions())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('account_number')
                ->label('Nomor rekening')
                ->helperText('Isi hanya nomor rekening.')
                ->required()
                ->maxLength(100),
            TextInput::make('account_holder')
                ->label('Nama pemilik rekening')
                ->helperText('Isi persis seperti nama yang tercatat di bank.')
                ->required()
                ->maxLength(255),
            Toggle::make('is_primary')
                ->label('Jadikan rekening utama untuk pembayaran')
                ->helperText('Aktifkan jika rekening ini yang paling sering digunakan.'),
            Hidden::make('verification_status')
                ->default(VerificationStatus::Pending->value)
                ->dehydrateStateUsing(fn () => VerificationStatus::Pending->value),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Usaha'),
            TextColumn::make('bank_name')->label('Bank')->searchable(),
            TextColumn::make('account_number')->label('Nomor Rekening')->searchable(),
            TextColumn::make('account_holder')->label('Nama Pemilik'),
            IconColumn::make('is_primary')->label('Rekening Utama')->boolean(),
            TextColumn::make('verification_status')->label('Status Pemeriksaan')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof VerificationStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
        ])->recordActions([
            EditAction::make()->label('Ubah'),
            Action::make('requestChange')
                ->label('Ganti Rekening')
                ->color('warning')
                ->visible(fn (SupplierBankAccount $record): bool => $record->verification_status === VerificationStatus::Verified && static::canEditOwned($record))
                ->fillForm(fn (SupplierBankAccount $record): array => [
                    'bank_id' => $record->bank_id,
                    'account_number' => $record->account_number,
                    'account_holder' => $record->account_holder,
                    'is_primary' => $record->is_primary,
                ])
                ->schema([
                    Select::make('bank_id')
                        ->label('Pilih bank')
                        ->placeholder('Ketik atau pilih nama bank')
                        ->helperText('Kode bank diisi otomatis.')
                        ->options(fn (): array => Bank::activeOptions())
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('account_number')
                        ->label('Nomor rekening')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('account_holder')
                        ->label('Nama pemilik rekening')
                        ->helperText('Isi persis seperti nama yang tercatat di bank.')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_primary')->label('Jadikan rekening utama'),
                ])
                ->action(function (SupplierBankAccount $record, array $data): void {
                    SupplierBankAccount::query()->create([
                        'supplier_id' => $record->supplier_id,
                        'bank_id' => $data['bank_id'],
                        'account_number' => $data['account_number'],
                        'account_holder' => $data['account_holder'],
                        'is_primary' => (bool) ($data['is_primary'] ?? false),
                        'verification_status' => VerificationStatus::Pending,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Perubahan rekening sudah dikirim.')
                        ->body('Rekening lama tetap digunakan sampai rekening baru selesai diperiksa admin.')
                        ->send();
                }),
            DeleteAction::make()
                ->label('Hapus')
                ->visible(fn (SupplierBankAccount $record) => in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['supplier', 'bank'])
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
        return $record instanceof SupplierBankAccount
            && in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)
            && static::canEditOwned($record);
    }

    private static function canEditOwned(SupplierBankAccount $record): bool
    {
        return in_array($record->supplier_id, static::supplierIds(), true) && static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof SupplierBankAccount
            && in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true)
            && static::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function supplierOptions(): array
    {
        return Supplier::query()->whereIn('id', static::supplierIds())->pluck('display_name', 'id')->all();
    }

    private static function singleSupplierId(): ?int
    {
        $options = static::supplierOptions();

        return count($options) === 1 ? (int) array_key_first($options) : null;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierBankAccounts::route('/')];
    }
}
