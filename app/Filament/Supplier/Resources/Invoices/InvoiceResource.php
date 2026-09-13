<?php

namespace App\Filament\Supplier\Resources\Invoices;

use App\Actions\Billing\SubmitInvoiceAction;
use App\Enums\InvoiceStatus;
use App\Enums\SystemPermission;
use App\Filament\Supplier\Resources\Invoices\Pages\ManageInvoices;
use App\Models\Invoice;
use DomainException;
use Filament\Actions\Action;
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
    protected static string | UnitEnum | null $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Nomor')->searchable()->sortable(),
            TextColumn::make('supplier_invoice_number')->label('Nomor Supplier')->searchable(),
            TextColumn::make('purchaseOrder.number')->label('PO')->searchable(),
            TextColumn::make('kitchen.name')->label('SPPG')->searchable(),
            TextColumn::make('invoice_date')->label('Tanggal')->date('d/m/Y'),
            TextColumn::make('due_date')->label('Jatuh Tempo')->date('d/m/Y'),
            TextColumn::make('po_amount')->label('Nilai PO')->money('IDR'),
            TextColumn::make('adjustment_amount')->label('Adjustment')->money('IDR')->toggleable(),
            TextColumn::make('payable_amount')->label('Payable')->money('IDR'),
            TextColumn::make('payments_count')->label('Pembayaran'),
            TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn ($state) => str($state instanceof InvoiceStatus ? $state->value : (string) $state)->replace('_', ' ')->title()),
        ])->recordActions([
            Action::make('submit')->label('Ajukan Invoice')->color('primary')->requiresConfirmation()
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft && static::owned($record))
                ->action(fn (Invoice $record) => static::run(
                    fn () => app(SubmitInvoiceAction::class)->execute($record, auth()->user()),
                    'Invoice berhasil diajukan.',
                )),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['purchaseOrder', 'kitchen'])
            ->withCount('payments')
            ->whereIn('supplier_id', static::supplierIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SystemPermission::InvoiceSubmit->value) ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function supplierIds(): array
    {
        return auth()->user()?->suppliers()->wherePivot('is_active', true)->pluck('suppliers.id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    private static function owned(Invoice $invoice): bool
    {
        return auth()->user()?->can(SystemPermission::InvoiceSubmit->value)
            && in_array($invoice->supplier_id, static::supplierIds(), true);
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
        return ['index' => ManageInvoices::route('/')];
    }
}
