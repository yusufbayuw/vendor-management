<?php

namespace App\Actions\Payment;

use App\Actions\Approval\RejectApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\PaymentStatus;
use App\Models\ApprovalRequest;
use App\Models\Payment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RejectPaymentAction
{
    public function __construct(private readonly RejectApprovalRequestAction $rejectApprovalRequest) {}

    public function execute(Payment $payment, User $actor, string $reason): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Submitted, PaymentStatus::UnderReview], true)) {
            throw new DomainException('Pembayaran tidak sedang menunggu verifikasi.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $payment->getMorphClass())
                ->where('approvable_id', $payment->getKey())
                ->where('process', GovernanceProcess::PaymentVerification->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $this->rejectApprovalRequest->execute($approvalRequest, $actor, $reason);

            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejection_reason' => $reason,
            ])->save();

            return $payment->refresh();
        });
    }
}
