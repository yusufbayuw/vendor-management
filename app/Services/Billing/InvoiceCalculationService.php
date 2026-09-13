<?php

namespace App\Services\Billing;

use App\Enums\InvoiceAdjustmentDirection;
use App\Enums\InvoiceAdjustmentStatus;
use App\Models\Invoice;

class InvoiceCalculationService
{
    public function recalculate(Invoice $invoice): Invoice
    {
        $adjustments = $invoice->adjustments()
            ->where('status', '!=', InvoiceAdjustmentStatus::Rejected->value)
            ->get();

        $adjustmentAmount = $adjustments->sum(function ($adjustment): float {
            $amount = (float) $adjustment->amount;

            return $adjustment->direction === InvoiceAdjustmentDirection::Deduction
                ? -$amount
                : $amount;
        });

        $totalAmount = (float) $invoice->po_amount;
        $payableAmount = max(
            0,
            $totalAmount + $adjustmentAmount - (float) $invoice->withholding_tax_amount,
        );

        $invoice->forceFill([
            'adjustment_amount' => round($adjustmentAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'payable_amount' => round($payableAmount, 2),
        ])->save();

        return $invoice->refresh();
    }
}
