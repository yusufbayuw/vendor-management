<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceAdjustmentDirection;
use App\Enums\InvoiceAdjustmentStatus;
use App\Enums\InvoiceAdjustmentType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\User;
use App\Services\Billing\InvoiceCalculationService;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddInvoiceAdjustmentAction
{
    public function __construct(private readonly InvoiceCalculationService $calculator) {}

    public function execute(
        Invoice $invoice,
        InvoiceAdjustmentType $type,
        InvoiceAdjustmentDirection $direction,
        float $amount,
        string $description,
        User $actor,
    ): InvoiceAdjustment {
        if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Submitted, InvoiceStatus::UnderReview], true)) {
            throw new DomainException('Adjustment tidak dapat ditambahkan setelah invoice disetujui.');
        }

        if ($amount <= 0 || blank($description)) {
            throw new DomainException('Nilai adjustment harus lebih dari nol dan memiliki keterangan.');
        }

        return DB::transaction(function () use ($invoice, $type, $direction, $amount, $description, $actor): InvoiceAdjustment {
            $adjustment = InvoiceAdjustment::query()->create([
                'invoice_id' => $invoice->getKey(),
                'type' => $type,
                'direction' => $direction,
                'description' => $description,
                'amount' => $amount,
                'status' => InvoiceAdjustmentStatus::Pending,
                'created_by' => $actor->getKey(),
            ]);

            $this->calculator->recalculate($invoice);

            return $adjustment->refresh();
        });
    }
}
