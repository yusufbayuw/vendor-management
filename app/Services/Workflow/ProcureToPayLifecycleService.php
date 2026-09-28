<?php

namespace App\Services\Workflow;

use App\Enums\BusinessFlowStage;
use App\Enums\DeliveryScheduleStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\DeliverySchedule;
use App\Models\FulfillmentDiscrepancy;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProcureToPayLifecycleService
{
    /**
     * @return array<string, array{
     *   stage: string,
     *   order: int,
     *   label: string,
     *   state: string,
     *   status: string,
     *   started_at: ?Carbon,
     *   completed_at: ?Carbon,
     *   duration_hours: ?float,
     *   is_complete: bool,
     *   is_current: bool,
     *   inferred: bool
     * }>
     */
    public function snapshot(PurchaseOrder $order): array
    {
        $this->load($order);

        $stages = [
            BusinessFlowStage::PurchaseRequest->value => $this->purchaseRequestStage($order),
            BusinessFlowStage::PurchaseOrder->value => $this->purchaseOrderStage($order),
            BusinessFlowStage::Delivery->value => $this->deliveryStage($order),
            BusinessFlowStage::Receiving->value => $this->receivingStage($order),
            BusinessFlowStage::Invoice->value => $this->invoiceStage($order),
            BusinessFlowStage::Payment->value => $this->paymentStage($order),
        ];

        $current = $this->currentStageFromSnapshot($stages);

        foreach ($stages as $key => $stage) {
            $stages[$key]['is_current'] = $key === $current->value;
        }

        return $stages;
    }

    /** @return array<string, mixed> */
    public function stage(PurchaseOrder $order, BusinessFlowStage $stage): array
    {
        return $this->snapshot($order)[$stage->value];
    }

    public function currentStage(PurchaseOrder $order): BusinessFlowStage
    {
        return $this->currentStageFromSnapshot($this->snapshot($order));
    }

    public function progress(PurchaseOrder $order): int
    {
        $completed = collect($this->snapshot($order))
            ->where('is_complete', true)
            ->count();

        return (int) round(($completed / count(BusinessFlowStage::cases())) * 100);
    }

    public function stageForType(string $type): ?BusinessFlowStage
    {
        return match (class_basename($type)) {
            'PurchaseRequest', 'PurchaseRequestItem', 'PurchaseAllocation' => BusinessFlowStage::PurchaseRequest,
            'PurchaseOrder', 'PurchaseOrderItem', 'PurchaseOrderResponse', 'ApprovalRequest', 'ApprovalAction' => BusinessFlowStage::PurchaseOrder,
            'DeliverySchedule', 'DeliveryScheduleItem' => BusinessFlowStage::Delivery,
            'GoodsReceipt', 'GoodsReceiptItem', 'GoodsReceiptAttachment', 'FulfillmentDiscrepancy' => BusinessFlowStage::Receiving,
            'Invoice', 'InvoiceAdjustment' => BusinessFlowStage::Invoice,
            'Payment', 'PaymentAttachment' => BusinessFlowStage::Payment,
            default => null,
        };
    }

    /**
     * Metadata stored with database notifications and reusable by audit/reporting.
     *
     * @return array{
     *   business_stage: ?string,
     *   business_stage_order: ?int,
     *   business_stage_label: ?string,
     *   transaction_po_id: ?int,
     *   transaction_po_number: ?string,
     *   purchase_request_id: ?int,
     *   purchase_request_number: ?string,
     *   current_stage: ?string,
     *   current_stage_order: ?int,
     *   flow_progress_percent: ?int
     * }
     */
    public function contextForEntity(?string $entityType, int|string|null $entityId): array
    {
        $empty = [
            'business_stage' => null,
            'business_stage_order' => null,
            'business_stage_label' => null,
            'transaction_po_id' => null,
            'transaction_po_number' => null,
            'purchase_request_id' => null,
            'purchase_request_number' => null,
            'current_stage' => null,
            'current_stage_order' => null,
            'flow_progress_percent' => null,
        ];

        if ($entityType === null || $entityId === null) {
            return $empty;
        }

        $stage = $this->stageForType($entityType);
        $model = $this->findEntity($entityType, $entityId);

        if ($stage === null || $model === null) {
            return $empty;
        }

        [$order, $request] = $this->resolveSpine($model);

        if ($order !== null) {
            $request ??= $order->purchaseRequest;
            $current = $this->currentStage($order);

            return [
                'business_stage' => $stage->value,
                'business_stage_order' => $stage->order(),
                'business_stage_label' => $stage->label(),
                'transaction_po_id' => (int) $order->getKey(),
                'transaction_po_number' => $order->number,
                'purchase_request_id' => $request?->getKey(),
                'purchase_request_number' => $request?->number,
                'current_stage' => $current->value,
                'current_stage_order' => $current->order(),
                'flow_progress_percent' => $this->progress($order),
            ];
        }

        return [
            'business_stage' => $stage->value,
            'business_stage_order' => $stage->order(),
            'business_stage_label' => $stage->label(),
            'transaction_po_id' => null,
            'transaction_po_number' => null,
            'purchase_request_id' => $request?->getKey(),
            'purchase_request_number' => $request?->number,
            'current_stage' => $stage->value,
            'current_stage_order' => $stage->order(),
            'flow_progress_percent' => 0,
        ];
    }

    private function load(PurchaseOrder $order): void
    {
        $order->loadMissing([
            'purchaseRequest',
            'deliverySchedules',
            'goodsReceipts',
            'invoice.payments',
        ]);
    }

    /** @return array<string, mixed> */
    private function purchaseRequestStage(PurchaseOrder $order): array
    {
        $request = $order->purchaseRequest;

        if ($request === null) {
            return $this->makeStage(BusinessFlowStage::PurchaseRequest, 'not_started', 'Tidak tersedia');
        }

        if ($request->status === PurchaseRequestStatus::Cancelled) {
            return $this->makeStage(
                BusinessFlowStage::PurchaseRequest,
                'cancelled',
                'Dibatalkan',
                $request->submitted_at ?? $request->created_at,
                $request->updated_at,
            );
        }

        if ($request->status === PurchaseRequestStatus::Rejected) {
            return $this->makeStage(
                BusinessFlowStage::PurchaseRequest,
                'blocked',
                'Ditolak',
                $request->submitted_at ?? $request->created_at,
                $request->rejected_at ?? $request->updated_at,
            );
        }

        $complete = $request->approved_at !== null
            || in_array($request->status, [
                PurchaseRequestStatus::Approved,
                PurchaseRequestStatus::PartiallyAllocated,
                PurchaseRequestStatus::FullyAllocated,
                PurchaseRequestStatus::PoGenerated,
                PurchaseRequestStatus::Closed,
            ], true)
            || $order->exists;

        $completedAt = $request->approved_at ?? ($complete ? $order->created_at : null);

        return $this->makeStage(
            BusinessFlowStage::PurchaseRequest,
            $complete ? 'completed' : 'in_progress',
            $complete ? 'Selesai' : str($request->status->value)->replace('_', ' ')->title()->toString(),
            $request->submitted_at ?? $request->created_at,
            $completedAt,
            $complete && $request->approved_at === null,
        );
    }

    /** @return array<string, mixed> */
    private function purchaseOrderStage(PurchaseOrder $order): array
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return $this->makeStage(
                BusinessFlowStage::PurchaseOrder,
                'cancelled',
                'Dibatalkan',
                $order->created_at,
                $order->updated_at,
            );
        }

        $downstreamExists = $order->deliverySchedules->isNotEmpty()
            || $order->goodsReceipts->isNotEmpty()
            || $order->invoice !== null;

        $complete = $order->acknowledged_at !== null
            || $downstreamExists
            || in_array($order->status, [
                PurchaseOrderStatus::Acknowledged,
                PurchaseOrderStatus::Scheduled,
                PurchaseOrderStatus::PartiallyDelivered,
                PurchaseOrderStatus::Fulfilled,
                PurchaseOrderStatus::PendingExceptionClosure,
                PurchaseOrderStatus::ClosedWithException,
                PurchaseOrderStatus::Invoiced,
                PurchaseOrderStatus::Paid,
                PurchaseOrderStatus::Closed,
            ], true);

        $fallback = $order->deliverySchedules->min('created_at')
            ?? $order->goodsReceipts->min('received_at')
            ?? $order->invoice?->created_at;

        return $this->makeStage(
            BusinessFlowStage::PurchaseOrder,
            $complete ? 'completed' : 'in_progress',
            $complete ? 'Selesai' : $this->poStatusLabel($order->status),
            $order->created_at,
            $order->acknowledged_at ?? ($complete ? $fallback : null),
            $complete && $order->acknowledged_at === null,
        );
    }

    /** @return array<string, mixed> */
    private function deliveryStage(PurchaseOrder $order): array
    {
        $schedules = $order->deliverySchedules;

        if ($schedules->isEmpty()) {
            if ($order->goodsReceipts->isNotEmpty() || $order->invoice !== null) {
                $completed = $order->goodsReceipts->min('received_at') ?? $order->invoice?->created_at;

                return $this->makeStage(
                    BusinessFlowStage::Delivery,
                    'completed',
                    'Selesai',
                    $order->acknowledged_at ?? $order->issued_at ?? $order->created_at,
                    $completed,
                    true,
                );
            }

            return $this->makeStage(BusinessFlowStage::Delivery, 'not_started', 'Belum dimulai');
        }

        $active = $schedules->reject(
            static fn (DeliverySchedule $schedule): bool => in_array($schedule->status, [
                DeliveryScheduleStatus::Received,
                DeliveryScheduleStatus::Cancelled,
            ], true),
        );

        $receivedAny = $order->goodsReceipts->isNotEmpty()
            || $schedules->contains(static fn (DeliverySchedule $schedule): bool => $schedule->status === DeliveryScheduleStatus::Received);

        $complete = $active->isEmpty() && $receivedAny;

        $startedAt = $schedules->min('created_at') ?? $schedules->min('planned_delivery_at');
        $completedAt = $complete
            ? ($order->goodsReceipts->max('received_at') ?? $schedules->max('updated_at'))
            : null;

        $latest = $schedules->sortByDesc('id')->first()?->status;
        $label = $complete
            ? 'Selesai'
            : ($latest instanceof DeliveryScheduleStatus
                ? str($latest->value)->replace('_', ' ')->title()->toString()
                : 'Sedang berjalan');

        return $this->makeStage(
            BusinessFlowStage::Delivery,
            $complete ? 'completed' : 'in_progress',
            $label,
            $startedAt,
            $completedAt,
        );
    }

    /** @return array<string, mixed> */
    private function receivingStage(PurchaseOrder $order): array
    {
        $receipts = $order->goodsReceipts;

        if ($receipts->isEmpty()) {
            if ($order->invoice !== null) {
                return $this->makeStage(
                    BusinessFlowStage::Receiving,
                    'completed',
                    'Selesai',
                    $order->deliverySchedules->min('planned_delivery_at') ?? $order->acknowledged_at,
                    $order->invoice->created_at,
                    true,
                );
            }

            return $this->makeStage(BusinessFlowStage::Receiving, 'not_started', 'Belum dimulai');
        }

        $nonCancelled = $receipts->reject(
            static fn (GoodsReceipt $receipt): bool => $receipt->status === GoodsReceiptStatus::Cancelled,
        );

        $pending = $nonCancelled->contains(
            static fn (GoodsReceipt $receipt): bool => $receipt->status === GoodsReceiptStatus::PendingInspection,
        );

        $complete = $nonCancelled->isNotEmpty()
            && ! $pending
            && $nonCancelled->every(
                static fn (GoodsReceipt $receipt): bool => $receipt->status === GoodsReceiptStatus::Completed,
            );

        return $this->makeStage(
            BusinessFlowStage::Receiving,
            $complete ? 'completed' : 'in_progress',
            $complete ? 'Selesai' : ($pending ? 'Menunggu QC' : 'Sedang berjalan'),
            $nonCancelled->min('received_at'),
            $complete ? $nonCancelled->max('inspected_at') : null,
        );
    }

    /** @return array<string, mixed> */
    private function invoiceStage(PurchaseOrder $order): array
    {
        $invoice = $order->invoice;

        if ($invoice === null) {
            return $this->makeStage(BusinessFlowStage::Invoice, 'not_started', 'Belum dimulai');
        }

        if ($invoice->status === InvoiceStatus::Cancelled) {
            return $this->makeStage(
                BusinessFlowStage::Invoice,
                'cancelled',
                'Dibatalkan',
                $invoice->issued_at ?? $invoice->created_at,
                $invoice->updated_at,
            );
        }

        if ($invoice->status === InvoiceStatus::Rejected) {
            return $this->makeStage(
                BusinessFlowStage::Invoice,
                'blocked',
                'Ditolak',
                $invoice->issued_at ?? $invoice->created_at,
                $invoice->updated_at,
            );
        }

        $complete = $invoice->approved_at !== null
            || $invoice->payments->isNotEmpty()
            || in_array($invoice->status, [
                InvoiceStatus::Approved,
                InvoiceStatus::PartiallyPaid,
                InvoiceStatus::Paid,
            ], true);

        $completedAt = $invoice->approved_at
            ?? ($complete ? $invoice->payments->min('created_at') : null);

        return $this->makeStage(
            BusinessFlowStage::Invoice,
            $complete ? 'completed' : 'in_progress',
            $complete ? 'Selesai' : str($invoice->status->value)->replace('_', ' ')->title()->toString(),
            $invoice->issued_at ?? $invoice->created_at,
            $completedAt,
            $complete && $invoice->approved_at === null,
        );
    }

    /** @return array<string, mixed> */
    private function paymentStage(PurchaseOrder $order): array
    {
        $invoice = $order->invoice;
        $payments = $invoice?->payments;

        if ($invoice === null || $payments === null || $payments->isEmpty()) {
            return $this->makeStage(BusinessFlowStage::Payment, 'not_started', 'Belum dimulai');
        }

        $complete = $invoice->status === InvoiceStatus::Paid
            || in_array($order->status, [PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed], true);

        $verifiedAt = $payments
            ->where('status', PaymentStatus::Verified)
            ->max('verified_at');

        $latest = $payments->sortByDesc('id')->first()?->status;

        $label = $complete
            ? 'Selesai'
            : match ($latest) {
                PaymentStatus::Submitted, PaymentStatus::UnderReview => 'Menunggu verifikasi',
                PaymentStatus::Rejected => 'Ditolak',
                PaymentStatus::Cancelled => 'Dibatalkan',
                PaymentStatus::Verified => 'Terverifikasi sebagian',
                default => 'Draft',
            };

        return $this->makeStage(
            BusinessFlowStage::Payment,
            $complete ? 'completed' : 'in_progress',
            $label,
            $payments->min('created_at'),
            $complete ? ($order->paid_at ?? $verifiedAt ?? $order->closed_at) : null,
            $complete && $verifiedAt === null && $order->paid_at === null,
        );
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    private function currentStageFromSnapshot(array $snapshot): BusinessFlowStage
    {
        foreach (BusinessFlowStage::cases() as $stage) {
            $state = $snapshot[$stage->value]['state'] ?? 'not_started';

            if ($state !== 'completed') {
                return $stage;
            }
        }

        return BusinessFlowStage::Payment;
    }

    /** @return array<string, mixed> */
    private function makeStage(
        BusinessFlowStage $stage,
        string $state,
        string $status,
        mixed $startedAt = null,
        mixed $completedAt = null,
        bool $inferred = false,
    ): array {
        $started = $this->carbon($startedAt);
        $completed = $this->carbon($completedAt);

        return [
            'stage' => $stage->value,
            'order' => $stage->order(),
            'label' => $stage->label(),
            'state' => $state,
            'status' => $status,
            'started_at' => $started,
            'completed_at' => $completed,
            'duration_hours' => $this->hours($started, $completed),
            'is_complete' => $state === 'completed',
            'is_current' => false,
            'inferred' => $inferred,
        ];
    }

    private function hours(?Carbon $from, ?Carbon $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 1);
    }

    private function carbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    private function poStatusLabel(PurchaseOrderStatus $status): string
    {
        return match ($status) {
            PurchaseOrderStatus::Draft => 'Draft',
            PurchaseOrderStatus::PendingApproval => 'Menunggu approval',
            PurchaseOrderStatus::Approved => 'Disetujui',
            PurchaseOrderStatus::Issued => 'Menunggu konfirmasi supplier',
            default => str($status->value)->replace('_', ' ')->title()->toString(),
        };
    }

    private function findEntity(string $entityType, int|string $entityId): ?Model
    {
        $class = match (class_basename($entityType)) {
            'PurchaseRequest' => PurchaseRequest::class,
            'PurchaseOrder' => PurchaseOrder::class,
            'DeliverySchedule' => DeliverySchedule::class,
            'GoodsReceipt' => GoodsReceipt::class,
            'FulfillmentDiscrepancy' => FulfillmentDiscrepancy::class,
            'Invoice' => Invoice::class,
            'InvoiceAdjustment' => InvoiceAdjustment::class,
            'Payment' => Payment::class,
            default => null,
        };

        return $class === null ? null : $class::query()->find($entityId);
    }

    /** @return array{?PurchaseOrder, ?PurchaseRequest} */
    private function resolveSpine(Model $model): array
    {
        if ($model instanceof PurchaseOrder) {
            $model->loadMissing('purchaseRequest');

            return [$model, $model->purchaseRequest];
        }

        if ($model instanceof PurchaseRequest) {
            $orders = $model->purchaseOrders()->limit(2)->get();
            $order = $orders->count() === 1 ? $orders->first() : null;

            return [$order, $model];
        }

        if ($model instanceof DeliverySchedule || $model instanceof GoodsReceipt || $model instanceof FulfillmentDiscrepancy) {
            $model->loadMissing('purchaseOrder.purchaseRequest');
            $order = $model->purchaseOrder;

            return [$order, $order?->purchaseRequest];
        }

        if ($model instanceof Invoice) {
            $model->loadMissing('purchaseOrder.purchaseRequest');

            return [$model->purchaseOrder, $model->purchaseOrder?->purchaseRequest];
        }

        if ($model instanceof InvoiceAdjustment) {
            $model->loadMissing('invoice.purchaseOrder.purchaseRequest');

            return [$model->invoice?->purchaseOrder, $model->invoice?->purchaseOrder?->purchaseRequest];
        }

        if ($model instanceof Payment) {
            $model->loadMissing('invoice.purchaseOrder.purchaseRequest');

            return [$model->invoice?->purchaseOrder, $model->invoice?->purchaseOrder?->purchaseRequest];
        }

        return [null, null];
    }
}
