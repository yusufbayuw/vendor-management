<?php

namespace App\Filament\Admin\Resources\SupplierDocuments;

use App\Actions\Supplier\RejectSupplierDocumentAction;
use App\Actions\Supplier\VerifySupplierDocumentAction;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\SupplierDocuments\Pages\ManageSupplierDocuments;
use App\Models\SupplierDocument;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierDocumentResource extends Resource
{
    protected static ?string $model = SupplierDocument::class;

    protected static ?string $navigationLabel = 'Verifikasi Dokumen';

    protected static ?string $modelLabel = 'dokumen supplier';

    protected static ?string $pluralModelLabel = 'dokumen supplier';

    protected static string|UnitEnum|null $navigationGroup = 'Supplier';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.display_name')->label('Supplier')->searchable()->sortable(),
            TextColumn::make('document_type')->label('Jenis')->searchable(),
            TextColumn::make('document_number')->label('Nomor')->searchable(),
            TextColumn::make('issued_at')->label('Terbit')->date('d/m/Y')->toggleable(),
            TextColumn::make('expires_at')->label('Berlaku Sampai')->date('d/m/Y')->sortable(),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof SupplierDocumentStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
            TextColumn::make('verifier.name')->label('Diverifikasi Oleh')->toggleable(),
            TextColumn::make('rejection_reason')->label('Catatan')->wrap()->toggleable(),
        ])->recordActions([
            Action::make('verify')->label('Verifikasi')->color('success')->requiresConfirmation()
                ->visible(fn (SupplierDocument $record) => in_array($record->status, [
                    SupplierDocumentStatus::Uploaded,
                    SupplierDocumentStatus::UnderReview,
                    SupplierDocumentStatus::Rejected,
                ], true) && static::canVerify($record))
                ->action(fn (SupplierDocument $record) => static::run(
                    fn () => app(VerifySupplierDocumentAction::class)->execute($record, auth()->user()),
                    'Dokumen supplier berhasil diverifikasi.',
                )),
            Action::make('reject')->label('Tolak')->color('danger')
                ->visible(fn (SupplierDocument $record) => in_array($record->status, [
                    SupplierDocumentStatus::Uploaded,
                    SupplierDocumentStatus::UnderReview,
                ], true) && static::canVerify($record))
                ->schema([Textarea::make('reason')->label('Alasan penolakan')->required()->rows(4)])
                ->action(fn (SupplierDocument $record, array $data) => static::run(
                    fn () => app(RejectSupplierDocumentAction::class)->execute($record, auth()->user(), $data['reason']),
                    'Dokumen supplier ditolak.',
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

    private static function canVerify(SupplierDocument $document): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can(SystemPermission::SupplierVerify->value)
            && app(UserAccessService::class)->canAccessSupplier($user, $document->supplier_id);
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
        return ['index' => ManageSupplierDocuments::route('/')];
    }
}
