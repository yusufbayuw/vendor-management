<?php

namespace App\Actions\Fulfillment;

use App\Actions\Approval\ApproveApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\DiscrepancyResolution;
use App\Enums\DiscrepancyStatus;
use App\Enums\GovernanceProcess;
use App\Enums\PurchaseOrderStatus;
use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrderExceptionCloseAction
{
    public function __construct(private readonly ApproveApprovalRequestAction $approveApprovalRequest) {}

    public function execute(
        PurchaseOrder $purchaseOrder,
        User $actor,
        string $resolutionNotes,
        ?string $overrideReason = null,
    ): PurchaseOrder {
        if ($purchaseOrder->status !== PurchaseOrderStatus::PendingExceptionClosure) {
            throw new DomainException('PO tidak sedang menunggu persetujuan close with exception.');
        }

        if (blank($resolutionNotes)) {
            throw new DomainException('Catatan penyelesaian discrepancy wajib diisi.');
        }

        return DB::transaction(function () use ($purchaseOrder, $actor, $resolutionNotes, $overrideReason): PurchaseOrder {
            $approvalRequest = ApprovalRequest::query()
                ->where('approvable_type', $purchaseOrder->getMorphClass())
                ->where('approvable_id', $purchaseOrder->getKey())
                ->where('process', GovernanceProcess::PurchaseOrderExceptionClosing->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->latest('id')
                ->firstOrFail();

            $approvalRequest = $this->approveApprovalRequest->execute(
                $approvalRequest,
                $actor,
                $resolutionNotes,
                $overrideReason,
            );

            if ($approvalRequest->status === ApprovalStatus::Approved) {
                $purchaseOrder->discrepancies()
                    ->where('status', DiscrepancyStatus::Open->value)
                    ->update([
                        'resolution' => DiscrepancyResolution::AcceptedException->value,
                        'resolution_notes' => $resolutionNotes,
                        'status' => DiscrepancyStatus::Resolved->value,
                        'approved_by' => $actor->getKey(),
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);

                $purchaseOrder->update(['status' => PurchaseOrderStatus::ClosedWithException]);
            }

            return $purchaseOrder->refresh();
        }, 3);
    }
}
