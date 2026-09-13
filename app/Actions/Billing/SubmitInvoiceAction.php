<?php

namespace App\Actions\Billing;

use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitInvoiceAction
{
    public function __construct(private readonly CreateApprovalRequestAction $createApprovalRequest) {}

    public function execute(Invoice $invoice, User $actor): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new DomainException('Hanya invoice draft yang dapat diajukan.');
        }

        $invoice->loadMissing('kitchen.organization');

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            $invoice->forceFill([
                'status' => InvoiceStatus::Submitted,
                'issued_at' => now(),
            ])->save();

            $this->createApprovalRequest->execute(
                $invoice,
                $invoice->kitchen->organization,
                GovernanceProcess::InvoiceApproval,
                $actor,
                (float) $invoice->payable_amount,
            );

            return $invoice->refresh();
        });
    }
}
