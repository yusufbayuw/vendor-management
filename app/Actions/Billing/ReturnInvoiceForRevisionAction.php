<?php

namespace App\Actions\Billing;

use App\Actions\Approval\RejectApprovalRequestAction;
use App\Enums\ApprovalStatus;
use App\Enums\GovernanceProcess;
use App\Enums\InvoiceStatus;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReturnInvoiceForRevisionAction
{
    public function __construct(
        private readonly RejectApprovalRequestAction $rejectApproval,
        private readonly AuditService $audit,
    ) {}

    public function execute(Invoice $invoice, User $actor, string $reason): Invoice
    {
        if (blank(trim($reason))) {
            throw new DomainException('Alasan pengembalian invoice untuk revisi wajib diisi.');
        }

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! in_array($invoice->status, [InvoiceStatus::Submitted, InvoiceStatus::UnderReview], true)) {
                throw new DomainException('Hanya invoice yang masih menunggu persetujuan dapat dikembalikan untuk revisi.');
            }

            if ($invoice->payments()->exists()) {
                throw new DomainException('Invoice dengan riwayat pembayaran tidak dapat dikembalikan untuk revisi.');
            }

            $approval = ApprovalRequest::query()
                ->where('approvable_type', $invoice->getMorphClass())
                ->where('approvable_id', $invoice->getKey())
                ->where('process', GovernanceProcess::InvoiceApproval->value)
                ->where('status', ApprovalStatus::Pending->value)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($approval === null) {
                throw new DomainException('Approval pending invoice tidak ditemukan atau telah diputuskan.');
            }

            // Reuse governance authorization and immutable decision history.
            // A rejected request cannot later be approved or reused on resubmit.
            $this->rejectApproval->execute($approval, $actor, 'Kembali untuk revisi: '.trim($reason));

            $oldValues = [
                'status' => $invoice->status->value,
                'payable_amount' => $invoice->payable_amount,
                'approval_request_id' => $approval->getKey(),
            ];

            $note = sprintf(
                'Revisi diminta %s oleh %s: %s',
                now()->format('d/m/Y H:i'),
                $actor->name,
                trim($reason),
            );

            $invoice->forceFill([
                'status' => InvoiceStatus::Draft,
                'issued_at' => null,
                'notes' => trim(implode("\n", array_filter([$invoice->notes, $note]))),
            ])->save();

            $this->audit->record($invoice, 'invoice_returned_for_revision', $oldValues, [
                'status' => InvoiceStatus::Draft->value,
                'approval_request_id' => $approval->getKey(),
                'reviewed_by' => $actor->getKey(),
                'reason' => trim($reason),
            ]);

            return $invoice->refresh();
        }, 3);
    }
}
