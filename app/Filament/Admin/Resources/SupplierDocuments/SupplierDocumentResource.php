<?php

namespace App\Filament\Admin\Resources\SupplierDocuments;

use App\Actions\Supplier\RejectSupplierDocumentAction;
use App\Actions\Supplier\VerifySupplierDocumentAction;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\SupplierDocuments\Pages\ManageSupplierDocuments;
use App\Filament\Support\SecureFileModal;
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
            TextColumn::make('file_path')
                ->label('File')
                ->icon('heroicon-o-paper-clip')
                ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : '-')
                ->action(SecureFileModal::make(
                    'previewSupplierDocument',
                    fn (SupplierDocument $record): string => route('files.supplier-documents.show', $record),
                    fn (SupplierDocument $record): string => route('files.supplier-documents.show', [
                        'supplierDocument' => $record,
                        'download' => 1,
                    ]),
                    fn (SupplierDocument $record): ?string => $record->file_path,
                )),
            TextColumn::make('issued_at')->label('Terbit')->date('d/m/Y')->toggleable(),
            TextColumn::make('expires_at')->label('Berlaku Sampai')->date('d/m/Y')->sortable(),
            TextColumn::make('status')->label('Status Catatan')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof SupplierDocumentStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
            TextColumn::make('verifier.name')->label('Diperiksa Oleh')->toggleable(),
            TextColumn::make('verification_note')->label('Keterangan Verifikasi')->wrap()->toggleable(),
        ])->recordActions([
            Action::make('verify')
                ->label('Catat Sesuai')
                ->color('success')
                ->visible(fn (SupplierDocument $record) => in_array($record->status, [
                    SupplierDocumentStatus::Uploaded,
                    SupplierDocumentStatus::UnderReview,
                    SupplierDocumentStatus::Rejected,
                ], true) && static::canVerify($record))
                ->schema([
                    Textarea::make('note')
                        ->label('Keterangan verifikasi')
                        ->helperText('Catatan ini bersifat administratif/informatif. Keputusan aktivasi supplier tetap dilakukan dari proses verifikasi supplier.')
                        ->rows(3),
                ])
                ->action(function (SupplierDocument $record, array $data): void {
                    abort_unless(static::canVerify($record), 403);

                    static::run(
                        fn () => app(VerifySupplierDocumentAction::class)->execute(
                            $record,
                            auth()->user(),
                            $data['note'] ?? null,
                        ),
                        'Keterangan dokumen supplier tersimpan.',
                    );
                }),

            Action::make('reject')
                ->label('Catat Perlu Perbaikan')
                ->color('warning')
                ->visible(fn (SupplierDocument $record) => in_array($record->status, [
                    SupplierDocumentStatus::Uploaded,
                    SupplierDocumentStatus::UnderReview,
                ], true) && static::canVerify($record))
                ->schema([
                    Textarea::make('reason')
                        ->label('Keterangan verifikasi')
                        ->helperText('Jelaskan data yang perlu diperbaiki. Catatan ini tidak otomatis menolak supplier.')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (SupplierDocument $record, array $data): void {
                    abort_unless(static::canVerify($record), 403);

                    static::run(
                        fn () => app(RejectSupplierDocumentAction::class)->execute($record, auth()->user(), $data['reason']),
                        'Keterangan perbaikan dokumen tersimpan.',
                    );
                }),
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
