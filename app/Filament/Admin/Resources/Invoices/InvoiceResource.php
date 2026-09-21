<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Actions\Billing\AddInvoiceAdjustmentAction;
use App\Actions\Billing\ApproveInvoiceAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Payment\CreatePaymentAction;
use App\Enums\InvoiceAdjustmentDirection;
use App\Enums\InvoiceAdjustmentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SystemPermission;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\Invoices\Pages\ManageInvoices;
use App\Filament\Support\SecureFileModal;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupplierBankAccount;
use App\Services\Access\UserAccessService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationLabel = 'Invoice';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $pluralModelLabel = 'invoice';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor Invoice')->searchable()->sortable(),
                TextColumn::make('supplier_invoice_number')->label('Invoice Supplier')->searchable()->toggleable(),
                TextColumn::make('purchaseOrder.number')->label('PO')->searchable()->sortable(),
                TextColumn::make('supplier.display_name')->label('Supplier')->searchable(),
                TextColumn::make('kitchen.name')->label('SPPG')->searchable(),
                TextColumn::make('invoice_date')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('due_date')->label('Jatuh Tempo')->date('d/m/Y')->sortable(),
                TextColumn::make('po_amount')->label('Nilai PO')->money('IDR')->sortable(),
                TextColumn::make('adjustment_amount')->label('Adjustment')->money('IDR')->toggleable(),
                TextColumn::make('payable_amount')->label('Payable')->money('IDR')->sortable(),
                TextColumn::make('invoice_file')
                    ->label('File Invoice')
                    ->icon('heroicon-o-paper-clip')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : '-')
                    ->action(SecureFileModal::make(
                        'previewInvoiceFile',
                        fn (Invoice $record): string => route('files.invoice-files.show', $record),
                        fn (Invoice $record): string => route('files.invoice-files.show', [
                            'invoice' => $record,
                            'download' => 1,
                        ]),
                        fn (Invoice $record): ?string => $record->invoice_file,
                    )),
                TextColumn::make('payments_count')->label('Pembayaran')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn ($state): string => static::statusLabel($state))
                    ->color(static fn ($state): string => static::statusColor($state)),
            ])
            ->recordActions([
                Action::make('adjustment')
                    ->label('Adjustment')
                    ->color('warning')
                    ->visible(static fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Draft, InvoiceStatus::Submitted, InvoiceStatus::UnderReview], true) && static::hasScopedPermission($record, SystemPermission::InvoiceReview))
                    ->schema([
                        Select::make('type')->label('Jenis')->options(static::adjustmentTypeOptions())->required(),
                        Select::make('direction')->label('Arah')->options([
                            InvoiceAdjustmentDirection::Addition->value => 'Penambahan',
                            InvoiceAdjustmentDirection::Deduction->value => 'Pengurangan',
                        ])->required(),
                        TextInput::make('amount')->label('Nilai')->numeric()->prefix('Rp')->minValue(0.01)->required(),
                        Textarea::make('description')->label('Keterangan')->required()->rows(3),
                    ])
                    ->action(static function (Invoice $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::InvoiceReview);
                        static::runDomainAction(fn () => app(AddInvoiceAdjustmentAction::class)->execute(
                            $record,
                            InvoiceAdjustmentType::from($data['type']),
                            InvoiceAdjustmentDirection::from($data['direction']),
                            (float) $data['amount'],
                            $data['description'],
                            auth()->user(),
                        ), 'Adjustment invoice berhasil ditambahkan.');
                    }),
                Action::make('submit')
                    ->label('Ajukan Invoice')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(static fn (Invoice $record): bool => $record->status === InvoiceStatus::Draft && static::canSubmit($record))
                    ->action(static function (Invoice $record): void {
                        abort_unless(static::canSubmit($record), 403);
                        static::runDomainAction(fn () => app(SubmitInvoiceAction::class)->execute($record, auth()->user()), 'Invoice berhasil diajukan untuk approval.');
                    }),
                Action::make('approve')
                    ->label('Setujui Invoice')
                    ->color('success')
                    ->visible(static fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Submitted, InvoiceStatus::UnderReview], true) && static::hasScopedPermission($record, SystemPermission::InvoiceApprove))
                    ->schema([
                        Textarea::make('comments')->label('Catatan approval')->rows(3),
                        Textarea::make('override_reason')->label('Alasan override (jika self approval)')->rows(3),
                    ])
                    ->action(static function (Invoice $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::InvoiceApprove);
                        static::runDomainAction(fn () => app(ApproveInvoiceAction::class)->execute(
                            $record,
                            auth()->user(),
                            $data['comments'] ?? null,
                            $data['override_reason'] ?? null,
                        ), 'Keputusan approval invoice berhasil disimpan.');
                    }),
                Action::make('createPayment')
                    ->label('Buat Pembayaran')
                    ->color('success')
                    ->visible(static fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true) && static::hasScopedPermission($record, SystemPermission::PaymentCreate))
                    ->schema(static fn (Invoice $record): array => [
                        DatePicker::make('payment_date')->label('Tanggal pembayaran')->native(false)->default(today())->required(),
                        TextInput::make('amount')->label('Jumlah pembayaran')->numeric()->prefix('Rp')->default(static::outstandingAmount($record))->minValue(0.01)->required(),
                        Select::make('payment_method')->label('Metode')->options(static::paymentMethodOptions())->default(PaymentMethod::BankTransfer->value)->required(),
                        TextInput::make('source_bank_name')->label('Bank sumber')->maxLength(255),
                        Select::make('destination_account_id')->label('Rekening tujuan supplier')->options(static::supplierBankAccountOptions($record))->searchable(),
                        TextInput::make('reference_number')->label('Nomor referensi')->maxLength(255),
                        Textarea::make('notes')->label('Catatan')->rows(3),
                    ])
                    ->action(static function (Invoice $record, array $data): void {
                        static::requireScopedPermission($record, SystemPermission::PaymentCreate);
                        static::runDomainAction(function () use ($record, $data): void {
                            $destinationAccount = filled($data['destination_account_id'] ?? null)
                                ? SupplierBankAccount::query()->where('supplier_id', $record->supplier_id)->findOrFail($data['destination_account_id'])
                                : null;

                            app(CreatePaymentAction::class)->execute(
                                $record,
                                (float) $data['amount'],
                                PaymentMethod::from($data['payment_method']),
                                auth()->user(),
                                $data['payment_date'],
                                $data['source_bank_name'] ?? null,
                                $data['reference_number'] ?? null,
                                $destinationAccount,
                                $data['notes'] ?? null,
                            );
                        }, 'Draft pembayaran berhasil dibuat.');
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['purchaseOrder', 'supplier', 'kitchen'])->withCount('payments');
        $user = auth()->user();

        return $user ? app(UserAccessService::class)->applyKitchenOwnedScope($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            SystemPermission::InvoiceSubmit,
            SystemPermission::InvoiceReview,
            SystemPermission::InvoiceApprove,
            SystemPermission::PaymentCreate,
            SystemPermission::PaymentVerify,
        ])->contains(static fn (SystemPermission $permission): bool => $user->can($permission->value));
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

    public static function canGenerateInvoice(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can(SystemPermission::InvoiceReview->value) || $user->can(SystemPermission::InvoiceSubmit->value));
    }

    private static function canSubmit(Invoice $record): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->can(SystemPermission::InvoiceSubmit->value) || $user->can(SystemPermission::InvoiceReview->value))
            && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function hasScopedPermission(Invoice $record, SystemPermission $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission->value) && app(UserAccessService::class)->canAccessKitchen($user, $record->sppg_kitchen_id);
    }

    private static function outstandingAmount(Invoice $invoice): float
    {
        $committed = (float) Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereNotIn('status', [PaymentStatus::Rejected->value, PaymentStatus::Cancelled->value])
            ->sum('amount');

        return max(0, (float) $invoice->payable_amount - $committed);
    }

    private static function supplierBankAccountOptions(Invoice $invoice): array
    {
        return SupplierBankAccount::query()
            ->where('supplier_id', $invoice->supplier_id)
            ->where('verification_status', VerificationStatus::Verified->value)
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get()
            ->mapWithKeys(static fn (SupplierBankAccount $account): array => [
                $account->getKey() => sprintf('%s — %s — %s', $account->bank_name, $account->account_number, $account->account_holder),
            ])->all();
    }

    private static function adjustmentTypeOptions(): array
    {
        return [
            InvoiceAdjustmentType::Penalty->value => 'Penalti',
            InvoiceAdjustmentType::Correction->value => 'Koreksi',
            InvoiceAdjustmentType::Credit->value => 'Kredit',
            InvoiceAdjustmentType::TaxCorrection->value => 'Koreksi Pajak',
            InvoiceAdjustmentType::Other->value => 'Lainnya',
        ];
    }

    private static function paymentMethodOptions(): array
    {
        return [
            PaymentMethod::BankTransfer->value => 'Transfer Bank',
            PaymentMethod::VirtualAccount->value => 'Virtual Account',
            PaymentMethod::Cash->value => 'Tunai',
            PaymentMethod::Other->value => 'Lainnya',
        ];
    }

    private static function requireScopedPermission(Invoice $record, SystemPermission $permission): void
    {
        abort_unless(static::hasScopedPermission($record, $permission), 403);
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

    private static function statusLabel($state): string
    {
        $status = $state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom((string) $state);

        return match ($status) {
            InvoiceStatus::Draft => 'Draft',
            InvoiceStatus::Submitted => 'Diajukan',
            InvoiceStatus::UnderReview => 'Dalam Review',
            InvoiceStatus::Approved => 'Disetujui',
            InvoiceStatus::PartiallyPaid => 'Dibayar Sebagian',
            InvoiceStatus::Paid => 'Lunas',
            InvoiceStatus::Rejected => 'Ditolak',
            InvoiceStatus::Cancelled => 'Dibatalkan',
            default => (string) $state,
        };
    }

    private static function statusColor($state): string
    {
        $status = $state instanceof InvoiceStatus ? $state : InvoiceStatus::tryFrom((string) $state);

        return match ($status) {
            InvoiceStatus::Approved, InvoiceStatus::Paid => 'success',
            InvoiceStatus::Submitted, InvoiceStatus::UnderReview, InvoiceStatus::PartiallyPaid => 'warning',
            InvoiceStatus::Rejected, InvoiceStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageInvoices::route('/')];
    }
}
