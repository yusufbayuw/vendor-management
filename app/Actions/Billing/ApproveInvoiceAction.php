<?php

namespace App\Actions\Billing;

use App\Actions\Approval\ApproveApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveInvoiceAction
{
    public function __construct(
        private readonly ApproveApprovalRequestAction $approveApprovalRequest,
        private readonly FinalizeApprovedInvoiceAction $finalizeApprovedInvoice,
    ) {}

    public function execute(
        Invoice $invoice,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): Invoice {
        if (! in_array($invoice->status, [InvoiceStatus::Submitted, InvoiceStatus::UnderReview], true)) {
            throw new DomainException('Invoice tidak sedang menunggu approval.');
        }

        return DB::transaction(function () use ($invoice, $actor, $comments, $overrideReason): Invoice {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $invoice->getMorphClass())
                ->where('approvable_id', $invoice->getKey())
                ->where('process', GovernanceProcess::InvoiceApproval->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $approvalRequest = $this->approveApprovalRequest->execute(
                $approvalRequest,
                $actor,
                $comments,
                $overrideReason,
            );

            if ($approvalRequest->status === ApprovalStatus::Approved) {
                return $this->finalizeApprovedInvoice->execute($invoice, $actor);
            }

            $invoice->update(['status' => InvoiceStatus::UnderReview]);

            return $invoice->refresh();
        });
    }
}
