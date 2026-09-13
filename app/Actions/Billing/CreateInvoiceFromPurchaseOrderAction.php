<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateInvoiceFromPurchaseOrderAction
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    public function execute(
        PurchaseOrder $purchaseOrder,
        User $actor,
        ?string $supplierInvoiceNumber = null,
        Carbon|string|null $invoiceDate = null,
        ?int $paymentTermDays = null,
        ?string $invoiceFile = null,
    ): Invoice {
        return DB::transaction(function () use ($purchaseOrder, $actor, $supplierInvoiceNumber, $invoiceDate, $paymentTermDays, $invoiceFile): Invoice {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['kitchen', 'supplier'])
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->getKey());

            if (! in_array($purchaseOrder->status, [
                PurchaseOrderStatus::Fulfilled,
                PurchaseOrderStatus::ClosedWithException,
            ], true)) {
                throw new DomainException('Invoice hanya dapat dibuat setelah PO fulfilled atau closed with exception.');
            }

            $existing = Invoice::query()->where('purchase_order_id', $purchaseOrder->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }

            $date = $invoiceDate === null ? today() : Carbon::parse($invoiceDate);
            $termDays = $paymentTermDays ?? 0;
            $poAmount = (float) $purchaseOrder->total_amount;

            return Invoice::query()->create([
                'number' => $this->documentNumbers->next('INV', $purchaseOrder->kitchen),
                'supplier_invoice_number' => $supplierInvoiceNumber,
                'purchase_order_id' => $purchaseOrder->getKey(),
                'supplier_id' => $purchaseOrder->supplier_id,
                'sppg_kitchen_id' => $purchaseOrder->sppg_kitchen_id,
                'invoice_date' => $date,
                'due_date' => $date->copy()->addDays($termDays),
                'po_amount' => $poAmount,
                'adjustment_amount' => 0,
                'withholding_tax_amount' => 0,
                'total_amount' => $poAmount,
                'payable_amount' => $poAmount,
                'status' => InvoiceStatus::Draft,
                'invoice_file' => $invoiceFile,
                'created_by' => $actor->getKey(),
            ]);
        }, 3);
    }
}
