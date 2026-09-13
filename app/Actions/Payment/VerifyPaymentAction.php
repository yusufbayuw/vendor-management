<?php

namespace App\Actions\Payment;

use App\Actions\Approval\ApproveApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\ApprovalRequest;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class VerifyPaymentAction
{
    public function __construct(private readonly ApproveApprovalRequestAction $approveApprovalRequest) {}

    public function execute(
        Payment $payment,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): Payment {
        if (! in_array($payment->status, [PaymentStatus::Submitted, PaymentStatus::UnderReview], true)) {
            throw new DomainException('Pembayaran tidak sedang menunggu verifikasi.');
        }

        return DB::transaction(function () use ($payment, $actor, $comments, $overrideReason): Payment {
            $payment = Payment::query()->with('invoice.purchaseOrder')->lockForUpdate()->findOrFail($payment->getKey());

            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $payment->getMorphClass())
                ->where('approvable_id', $payment->getKey())
                ->where('process', GovernanceProcess::PaymentVerification->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $approvalRequest = $this->approveApprovalRequest->execute(
                $approvalRequest,
                $actor,
                $comments,
                $overrideReason,
            );

            if ($approvalRequest->status !== ApprovalStatus::Approved) {
                $payment->update(['status' => PaymentStatus::UnderReview]);

                return $payment->refresh();
            }

            $payment->forceFill([
                'status' => PaymentStatus::Verified,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ])->save();

            $invoice = $payment->invoice()->lockForUpdate()->firstOrFail();
            $verifiedAmount = (float) $invoice->payments()
                ->where('status', PaymentStatus::Verified->value)
                ->sum('amount');

            if ($verifiedAmount + 0.01 >= (float) $invoice->payable_amount) {
                $invoice->update(['status' => InvoiceStatus::Paid]);
                $invoice->purchaseOrder()->update([
                    'status' => PurchaseOrderStatus::Closed,
                    'paid_at' => now(),
                    'closed_at' => now(),
                ]);
            } else {
                $invoice->update(['status' => InvoiceStatus::PartiallyPaid]);
            }

            return $payment->refresh();
        }, 3);
    }
}
