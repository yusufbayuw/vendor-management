<?php

namespace App\Filament\Supplier\Resources\Invoices\Pages;

use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource;
use App\Models\PurchaseOrder;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageInvoices extends ManageRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createInvoice')->label('Buat Invoice')->color('primary')
                ->visible(fn () => auth()->user()?->can('invoice.submit') ?? false)
                ->schema([
                    Select::make('purchase_order_id')->label('PO')->options(fn () => $this->poOptions())->searchable()->required(),
                    TextInput::make('supplier_invoice_number')->label('Nomor Invoice Supplier')->required()->maxLength(255),
                    DatePicker::make('invoice_date')->label('Tanggal Invoice')->native(false)->default(today())->required(),
                    TextInput::make('payment_term_days')->label('Termin (hari)')->numeric()->minValue(0)->default(0)->required(),
                    FileUpload::make('invoice_file')->label('File Invoice')->disk('local')->directory('invoices')->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->required(),
                ])->action(function (array $data): void {
                    $po = PurchaseOrder::query()->whereIn('supplier_id', InvoiceResource::supplierIds())->findOrFail($data['purchase_order_id']);
                    try {
                        app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
                            $po,
                            auth()->user(),
                            $data['supplier_invoice_number'],
                            $data['invoice_date'],
                            (int) $data['payment_term_days'],
                            $data['invoice_file'],
                        );
                        Notification::make()->success()->title('Invoice draft berhasil dibuat.')->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }

    private function poOptions(): array
    {
        return PurchaseOrder::query()
            ->with('kitchen')
            ->whereIn('supplier_id', InvoiceResource::supplierIds())
            ->whereIn('status', [PurchaseOrderStatus::Fulfilled->value, PurchaseOrderStatus::ClosedWithException->value])
            ->whereDoesntHave('invoice')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (PurchaseOrder $po) => [
                $po->getKey() => sprintf('%s — %s — Rp %s', $po->number, $po->kitchen?->name ?? 'SPPG', number_format((float) $po->total_amount, 0, ',', '.')),
            ])->all();
    }
}
