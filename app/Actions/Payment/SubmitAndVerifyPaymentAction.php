<?php

namespace App\Actions\Payment;

use App\Enums\ApprovalDecisionSource;
use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Enums\SystemPermission;
use App\Models\Payment;
use App\Models\User;
use App\Services\Governance\GovernancePolicyService;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitAndVerifyPaymentAction
{
    public function __construct(
        private readonly SubmitPaymentForVerificationAction $submitPayment,
        private readonly VerifyPaymentAction $verifyPayment,
        private readonly GovernancePolicyService $governancePolicyService,
    ) {}

    public function execute(
        Payment $payment,
        User $actor,
        ?string $comments = null,
        ?string $overrideReason = null,
    ): Payment {
        $payment->loadMissing('invoice.kitchen.organization');

        $organization = $payment->invoice->kitchen->organization;

        if ($organization->operational_profile !== OperationalProfile::Lean) {
            throw new DomainException('Aksi satu langkah hanya tersedia untuk profil Lean.');
        }

        if (! $actor->can(SystemPermission::PaymentCreate->value) || ! $actor->can(SystemPermission::PaymentVerify->value)) {
            throw new DomainException('User harus memiliki izin membuat dan memverifikasi pembayaran.');
        }

        $policy = $this->governancePolicyService->snapshot(
            $organization,
            GovernanceProcess::PaymentVerification,
            (float) $payment->amount,
        );

        if (! $policy['self_approval_allowed'] || $policy['minimum_approvers'] !== 1) {
            throw new DomainException('Kebijakan pembayaran ini tetap memerlukan verifikasi terpisah.');
        }

        if ($policy['requires_override_reason'] && blank($overrideReason)) {
            throw new DomainException('Alasan override wajib diisi untuk verifikasi pembayaran ini.');
        }

        return DB::transaction(function () use ($payment, $actor, $comments, $overrideReason): Payment {
            $payment = $this->submitPayment->execute($payment, $actor);

            return $this->verifyPayment->execute(
                $payment,
                $actor,
                $comments ?: 'Pembayaran dikonfirmasi dan diverifikasi dalam satu interaksi Lean.',
                $overrideReason,
                ApprovalDecisionSource::ExplicitSelfApproval,
            );
        }, 3);
    }
}
