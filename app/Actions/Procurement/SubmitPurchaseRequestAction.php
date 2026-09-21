<?php

namespace App\Actions\Procurement;

use App\Actions\Approval\AutoSelfApproveApprovalRequestAction;
use App\Actions\Approval\CreateApprovalRequestAction;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseRequestAction
{
    public function __construct(
        private readonly CreateApprovalRequestAction $createApprovalRequest,
        private readonly AutoSelfApproveApprovalRequestAction $autoSelfApprove,
    ) {}

    public function execute(PurchaseRequest $purchaseRequest, User $actor): PurchaseRequest
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::Draft) {
            throw new DomainException('Hanya purchase request draft yang dapat diajukan.');
        }

        $purchaseRequest->loadMissing(['items', 'kitchen.organization']);

        if ($purchaseRequest->items->isEmpty()) {
            throw new DomainException('Purchase request harus memiliki minimal satu item.');
        }

        if ($purchaseRequest->items->contains(fn ($item) => (float) $item->requested_qty <= 0)) {
            throw new DomainException('Jumlah kebutuhan setiap item harus lebih dari nol.');
        }

        $amount = $purchaseRequest->items->sum(
            fn ($item) => (float) ($item->estimated_total ?? ((float) $item->requested_qty * (float) ($item->estimated_unit_price ?? 0))),
        );

        return DB::transaction(function () use ($purchaseRequest, $actor, $amount): PurchaseRequest {
            $purchaseRequest->forceFill([
                'status' => PurchaseRequestStatus::Submitted,
                'submitted_at' => now(),
            ])->save();

            $approvalRequest = $this->createApprovalRequest->execute(
                $purchaseRequest,
                $purchaseRequest->kitchen->organization,
                GovernanceProcess::PurchaseRequestApproval,
                $actor,
                $amount,
            );

            $approvalRequest = $this->autoSelfApprove->execute($approvalRequest, $actor);

            if ($approvalRequest->status === \App\Enums\ApprovalStatus::Approved) {
                $purchaseRequest->forceFill([
                    'status' => PurchaseRequestStatus::Approved,
                    'approved_at' => now(),
                    'approved_by' => $actor->getKey(),
                ])->save();
            }

            return $purchaseRequest->refresh();
        });
    }
}
