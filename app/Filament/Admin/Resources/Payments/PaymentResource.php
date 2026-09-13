<?php

namespace App\Filament\Admin\Resources\Payments;

use App\Actions\Payment\AttachPaymentProofAction;
use App\Actions\Payment\RejectPaymentAction;
use App\Actions\Payment\SubmitPaymentForVerificationAction;
use App\Actions\Payment\VerifyPaymentAction;
use App\Enums\PaymentAttachmentType;
use App\Enums\PaymentStatus;
use App\Enums\SystemPermission;
use App\Filament\Admin\Resources\Payments\Pages\ManagePayments;
use App\Models\Payment;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;
    protected static ?string $navigationLabel = 'Pembayaran';
    protected static string | UnitEnum | null $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Nomor')->searchable()->sortable(),
            TextColumn::make('invoice.number')->label('Invoice')->searchable(),
            TextColumn::make('invoice.supplier.display_name')->label('Supplier')->searchable(),
            TextColumn::make('invoice.kitchen.name')->label('SPPG')->searchable(),
            TextColumn::make('payment_date')->label('Tanggal')->date('d/m/Y')->sortable(),
            TextColumn::make('amount')->label('Jumlah')->money('IDR')->sortable(),
            TextColumn::make('reference_number')->label('Referensi')->toggleable(),
            TextColumn::make('attachments_count')->label('Bukti'),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof PaymentStatus ? $state->value : (string) $state)->replace('_', ' ')->title())
                ->color(fn ($state) => match ($state instanceof PaymentStatus ? $state : PaymentStatus::tryFrom((string) $state)) {
                    PaymentStatus::Verified => 'success',
                    PaymentStatus::Submitted, PaymentStatus::UnderReview => 'warning',
                    PaymentStatus::Rejected, PaymentStatus::Cancelled => 'danger',
                    default => 'gray',
                }),
        ])->recordActions([
            Action::make('proof')->label('Upload Bukti')->color('primary')
                ->visible(fn (Payment $record) => in_array($record->status, [PaymentStatus::Draft, PaymentStatus::Submitted, PaymentStatus::UnderReview], true) && static::allowed($record, SystemPermission::PaymentCreate))
                ->schema([
                    Select::make('type')->label('Jenis')->options([
                        PaymentAttachmentType::PaymentProof->value => 'Bukti Pembayaran',
                        PaymentAttachmentType::BankStatement->value => 'Rekening Koran',
                        PaymentAttachmentType::Other->value => 'Lainnya',
                    ])->default(PaymentAttachmentType::PaymentProof->value)->required(),
                    FileUpload::make('files')->label('File')->disk('local')->directory('payments')->visibility('private')
                        ->multiple()->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)->required(),
                    Textarea::make('caption')->label('Keterangan')->rows(2),
                ])->action(function (Payment $record, array $data): void {
                    static::guard(SystemPermission::PaymentCreate);
                    static::run(function () use ($record, $data): void {
                        $disk = Storage::disk('local');
                        foreach ((array) $data['files'] as $path) {
                            app(AttachPaymentProofAction::class)->execute(
                                $record, $path, auth()->user(), PaymentAttachmentType::from($data['type']),
                                $disk->exists($path) ? $disk->mimeType($path) : null,
                                $disk->exists($path) ? $disk->size($path) : null,
                                $data['caption'] ?? null,
                            );
                        }
                    }, 'Bukti pembayaran berhasil di-upload.');
                }),
            Action::make('submit')->label('Ajukan Verifikasi')->color('warning')->requiresConfirmation()
                ->visible(fn (Payment $record) => $record->status === PaymentStatus::Draft && static::allowed($record, SystemPermission::PaymentCreate))
                ->action(function (Payment $record): void {
                    static::guard(SystemPermission::PaymentCreate);
                    static::run(fn () => app(SubmitPaymentForVerificationAction::class)->execute($record, auth()->user()), 'Pembayaran diajukan untuk verifikasi.');
                }),
            Action::make('verify')->label('Verifikasi')->color('success')
                ->visible(fn (Payment $record) => in_array($record->status, [PaymentStatus::Submitted, PaymentStatus::UnderReview], true) && static::allowed($record, SystemPermission::PaymentVerify))
                ->schema([
                    Textarea::make('comments')->label('Catatan')->rows(3),
                    Textarea::make('override_reason')->label('Alasan override')->rows(3),
                ])->action(function (Payment $record, array $data): void {
                    static::guard(SystemPermission::PaymentVerify);
                    static::run(fn () => app(VerifyPaymentAction::class)->execute($record, auth()->user(), $data['comments'] ?? null, $data['override_reason'] ?? null), 'Pembayaran berhasil diverifikasi.');
                }),
            Action::make('reject')->label('Tolak')->color('danger')
                ->visible(fn (Payment $record) => in_array($record->status, [PaymentStatus::Submitted, PaymentStatus::UnderReview], true) && static::allowed($record, SystemPermission::PaymentVerify))
                ->schema([Textarea::make('reason')->label('Alasan')->required()->rows(4)])
                ->action(function (Payment $record, array $data): void {
                    static::guard(SystemPermission::PaymentVerify);
                    static::run(fn () => app(RejectPaymentAction::class)->execute($record, auth()->user(), $data['reason']), 'Pembayaran ditolak.');
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['invoice.supplier', 'invoice.kitchen'])->withCount('attachments');
        $user = auth()->user();
        if (! $user) return $query->whereRaw('1 = 0');
        if (app(UserAccessService::class)->hasGlobalAccess($user)) return $query;

        return $query->whereHas('invoice', fn (Builder $q) => app(UserAccessService::class)->applyKitchenOwnedScope($q, $user));
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && ($user->can(SystemPermission::PaymentCreate->value) || $user->can(SystemPermission::PaymentVerify->value));
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    private static function allowed(Payment $payment, SystemPermission $permission): bool
    {
        $user = auth()->user();
        $payment->loadMissing('invoice');
        return $user && $user->can($permission->value)
            && app(UserAccessService::class)->canAccessKitchen($user, $payment->invoice->sppg_kitchen_id);
    }

    private static function guard(SystemPermission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
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
        return ['index' => ManagePayments::route('/')];
    }
}
