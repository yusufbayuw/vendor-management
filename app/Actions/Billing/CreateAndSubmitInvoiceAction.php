<?php

namespace App\Actions\Billing;

use App\Enums\OperationalProfile;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAndSubmitInvoiceAction
{
    public function __construct(
        private readonly CreateInvoiceFromPurchaseOrderAction $createInvoice,
        private readonly SubmitInvoiceAction $submitInvoice,
    ) {}

    public function execute(
        PurchaseOrder $purchaseOrder,
        User $actor,
        ?string $supplierInvoiceNumber = null,
        Carbon|string|null $invoiceDate = null,
        ?int $paymentTermDays = null,
        ?string $invoiceFile = null,
    ): Invoice {
        $purchaseOrder->loadMissing('kitchen.organization');

        if ($purchaseOrder->kitchen?->organization?->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Pembuatan dan pengajuan invoice satu langkah hanya tersedia untuk profil Lean.');
        }

        return DB::transaction(function () use (
            $purchaseOrder,
            $actor,
            $supplierInvoiceNumber,
            $invoiceDate,
            $paymentTermDays,
            $invoiceFile,
        ): Invoice {
            $invoice = $this->createInvoice->execute(
                $purchaseOrder,
                $actor,
                $supplierInvoiceNumber,
                $invoiceDate,
                $paymentTermDays,
                $invoiceFile,
            );

            return $this->submitInvoice->execute($invoice, $actor);
        }, 3);
    }
}
