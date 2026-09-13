<?php

namespace App\Filament\Admin\Resources\Suppliers;

use App\Actions\Supplier\ApproveSupplierAction;
use App\Actions\Supplier\RequestSupplierRevisionAction;
use App\Actions\Supplier\StartSupplierReviewAction;
use App\Actions\Supplier\SuspendSupplierAction;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static ?string $navigationLabel = 'Supplier';
    protected static ?string $modelLabel = 'supplier';
    protected static ?string $pluralModelLabel = 'supplier';
    protected static string | UnitEnum | null $navigationGroup = 'Supplier';
    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Nama Supplier')->searchable()->sortable(),
                TextColumn::make('legal_name')->label('Nama Legal')->searchable()->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => $state instanceof SupplierStatus ? $state->label() : (SupplierStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(static fn ($state): string => match ($state instanceof SupplierStatus ? $state : SupplierStatus::tryFrom((string) $state)) {
                        SupplierStatus::Active => 'success',
                        SupplierStatus::Submitted, SupplierStatus::UnderReview => 'warning',
                        SupplierStatus::RevisionRequired => 'info',
                        SupplierStatus::Suspended, SupplierStatus::Rejected => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('phone')->label('Telepon')->toggleable(),
                TextColumn::make('documents_count')->label('Dokumen')->sortable(),
                TextColumn::make('products_count')->label('Produk')->sortable(),
            ])
            ->recordActions([
                Action::make('startReview')
                    ->label('Mulai Verifikasi')
                    ->visible(static fn (Supplier $record): bool => $record->status === SupplierStatus::Submitted && static::canVerify())
                    ->requiresConfirmation()
                    ->action(static function (Supplier $record): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(StartSupplierReviewAction::class)->execute($record), 'Verifikasi supplier dimulai.');
                    }),
                Action::make('requestRevision')
                    ->label('Minta Perbaikan')
                    ->color('warning')
                    ->visible(static fn (Supplier $record): bool => in_array($record->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true) && static::canVerify())
                    ->schema([
                        Textarea::make('reason')->label('Catatan perbaikan')->required()->rows(4),
                    ])
                    ->action(static function (Supplier $record, array $data): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(RequestSupplierRevisionAction::class)->execute($record, $data['reason']), 'Permintaan perbaikan dikirim.');
                    }),
                Action::make('approve')
                    ->label('Setujui Supplier')
                    ->color('success')
                    ->visible(static fn (Supplier $record): bool => in_array($record->status, [SupplierStatus::Submitted, SupplierStatus::UnderReview], true) && static::canVerify())
                    ->requiresConfirmation()
                    ->action(static function (Supplier $record): void {
                        static::requirePermission(SystemPermission::SupplierVerify);
                        static::runDomainAction(fn () => app(ApproveSupplierAction::class)->execute($record, auth()->user()), 'Supplier berhasil diaktifkan.');
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->color('danger')
                    ->visible(static fn (Supplier $record): bool => $record->status === SupplierStatus::Active && (auth()->user()?->can(SystemPermission::SupplierSuspend->value) ?? false))
                    ->schema([
                        Textarea::make('reason')->label('Alasan penangguhan')->required()->rows(4),
                    ])
                    ->action(static function (Supplier $record, array $data): void {
                        static::requirePermission(SystemPermission::SupplierSuspend);
                        static::runDomainAction(fn () => app(SuspendSupplierAction::class)->execute($record, $data['reason']), 'Supplier ditangguhkan.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['documents', 'products']);
        $user = auth()->user();

        return $user
            ? app(UserAccessService::class)->applySupplierScope($query, $user)
            : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }

    private static function canVerify(): bool
    {
        return auth()->user()?->can(SystemPermission::SupplierVerify->value) ?? false;
    }

    private static function requirePermission(SystemPermission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }

    private static function runDomainAction(callable $action, string $successMessage): void
    {
        try {
            $action();
            Notification::make()->success()->title($successMessage)->send();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
