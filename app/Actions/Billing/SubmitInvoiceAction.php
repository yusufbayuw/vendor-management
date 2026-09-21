<?php

namespace App\Actions\Billing;

use App\Actions\Approval\AutoSelfApproveApprovalRequestAction;
use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitInvoiceAction
{
    public function __construct(
        private readonly CreateApprovalRequestAction $createApprovalRequest,
        private readonly AutoSelfApproveApprovalRequestAction $autoSelfApprove,
        private readonly FinalizeApprovedInvoiceAction $finalizeApprovedInvoice,
    ) {}

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

            $approvalRequest = $this->createApprovalRequest->execute(
                $invoice,
                $invoice->kitchen->organization,
                GovernanceProcess::InvoiceApproval,
                $actor,
                (float) $invoice->payable_amount,
            );

            $approvalRequest = $this->autoSelfApprove->execute($approvalRequest, $actor);

            if ($approvalRequest->status === ApprovalStatus::Approved) {
                return $this->finalizeApprovedInvoice->execute($invoice, $actor);
            }

            return $invoice->refresh();
        });
    }
}
