<?php

namespace App\Filament\Admin\Resources\SupplierBankAccounts;

use App\Actions\Supplier\RejectSupplierBankAccountAction;
use App\Actions\Supplier\VerifySupplierBankAccountAction;
use App\Enums\SystemPermission;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\SupplierBankAccounts\Pages\ManageSupplierBankAccounts;
use App\Models\SupplierBankAccount;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierBankAccountResource extends Resource
{
    protected static ?string $model = SupplierBankAccount::class;

    protected static ?string $navigationLabel = 'Verifikasi Rekening';

    protected static ?string $modelLabel = 'rekening supplier';

    protected static ?string $pluralModelLabel = 'rekening supplier';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Supplier')->searchable()->sortable(),
            TextColumn::make('bank_name')->label('Bank')->searchable(),
            TextColumn::make('account_number')->label('Nomor Rekening')->searchable(),
            TextColumn::make('account_holder')->label('Atas Nama')->searchable(),
            IconColumn::make('is_primary')->label('Utama')->boolean(),
            TextColumn::make('verification_status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof VerificationStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
            TextColumn::make('verifier.name')->label('Diverifikasi Oleh')->toggleable(),
            TextColumn::make('verified_at')->label('Waktu Verifikasi')->dateTime('d/m/Y H:i')->toggleable(),
            TextColumn::make('rejection_reason')->label('Catatan')->wrap()->toggleable(),
        ])->recordActions([
            Action::make('verify')->label('Verifikasi')->color('success')->requiresConfirmation()
                ->visible(fn (SupplierBankAccount $record) => in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Rejected], true) && static::canVerify($record))
                ->action(fn (SupplierBankAccount $record) => static::run(
                    fn () => app(VerifySupplierBankAccountAction::class)->execute($record, auth()->user()),
                    'Rekening supplier berhasil diverifikasi.',
                )),
            Action::make('reject')->label('Tolak')->color('danger')
                ->visible(fn (SupplierBankAccount $record) => in_array($record->verification_status, [VerificationStatus::Pending, VerificationStatus::Verified], true) && static::canVerify($record))
                ->schema([Textarea::make('reason')->label('Alasan penolakan')->required()->rows(4)])
                ->action(fn (SupplierBankAccount $record, array $data) => static::run(
                    fn () => app(RejectSupplierBankAccountAction::class)->execute($record, auth()->user(), $data['reason']),
                    'Rekening supplier ditolak.',
                )),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['supplier', 'verifier']);
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('supplier', fn (Builder $supplierQuery) => app(UserAccessService::class)->applySupplierScope($supplierQuery, $user));
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierVerify->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function canVerify(SupplierBankAccount $account): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can(SystemPermission::SupplierVerify->value)
            && app(UserAccessService::class)->canAccessSupplier($user, $account->supplier_id);
    }

    private static function run(callable $callback, string $message): void
    {
        try {
            $callback();
            Notification::make()->success()->title($message)->send();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupplierBankAccounts::route('/')];
    }
}
