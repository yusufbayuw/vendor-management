<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\PurchaseOrder;
use App\Services\Access\UserAccessService;
use App\Services\Files\VendorFileStorage;
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
            Action::make('generateInvoice')
                ->label('Generate Invoice')
                ->color('primary')
                ->visible(fn (): bool => InvoiceResource::canGenerateInvoice())
                ->schema([
                    Select::make('purchase_order_id')
                        ->label('Purchase Order')
                        ->options(fn (): array => $this->eligiblePurchaseOrderOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('supplier_invoice_number')->label('Nomor invoice supplier')->maxLength(255),
                    DatePicker::make('invoice_date')->label('Tanggal invoice')->native(false)->default(today())->required(),
                    TextInput::make('payment_term_days')->label('Termin pembayaran (hari)')->numeric()->minValue(0)->default(0)->required(),
                    FileUpload::make('invoice_file')
                        ->label('File invoice supplier')
                        ->disk(VendorFileStorage::DISK)
                        ->directory('invoices')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240),
                ])
                ->action(function (array $data): void {
                    $purchaseOrder = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
                    $user = auth()->user();

                    abort_unless(
                        $user !== null
                        && InvoiceResource::canGenerateInvoice()
                        && app(UserAccessService::class)->canAccessKitchen($user, $purchaseOrder->sppg_kitchen_id),
                        403,
                    );

                    try {
                        app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
                            $purchaseOrder,
                            $user,
                            $data['supplier_invoice_number'] ?? null,
                            $data['invoice_date'],
                            (int) $data['payment_term_days'],
                            $data['invoice_file'] ?? null,
                        );
                        Notification::make()->success()->title('Invoice draft berhasil dibuat dari PO.')->send();
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                    }
                }),
        ];
    }

    private function eligiblePurchaseOrderOptions(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $query = PurchaseOrder::query()
            ->with(['supplier', 'kitchen'])
            ->whereIn('status', [
                PurchaseOrderStatus::Fulfilled->value,
                PurchaseOrderStatus::ClosedWithException->value,
            ])
            ->whereDoesntHave('invoice')
            ->orderByDesc('id');

        $query = app(UserAccessService::class)->applyKitchenOwnedScope($query, $user);

        return $query->get()->mapWithKeys(static fn (PurchaseOrder $po): array => [
            $po->getKey() => sprintf(
                '%s — %s — %s — Rp %s',
                $po->number,
                $po->supplier?->display_name ?? 'Supplier',
                $po->kitchen?->name ?? 'SPPG',
                number_format((float) $po->total_amount, 0, ',', '.'),
            ),
        ])->all();
    }
}
