<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceAdjustmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceCalculationService;
use Illuminate\Support\Facades\DB;

class FinalizeApprovedInvoiceAction
{
    public function __construct(
        private readonly InvoiceCalculationService $calculator,
    ) {}

    public function execute(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $adjustments = $invoice->adjustments()
                ->where('status', InvoiceAdjustmentStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            foreach ($adjustments as $adjustment) {
                $adjustment->forceFill([
                    'status' => InvoiceAdjustmentStatus::Approved,
                    'approved_by' => $actor->getKey(),
                    'approved_at' => now(),
                ])->save();
            }

            $this->calculator->recalculate($invoice);

            $invoice->forceFill([
                'status' => InvoiceStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->getKey(),
            ])->save();

            $purchaseOrder = $invoice->purchaseOrder()
                ->lockForUpdate()
                ->firstOrFail();

            $purchaseOrder->update(['status' => PurchaseOrderStatus::Invoiced]);

            return $invoice->refresh();
        });
    }
}
