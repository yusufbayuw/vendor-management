<?php

namespace App\Observers;

use App\Enums\GoodsReceiptStatus;
use App\Events\GoodsReceiptStatusChanged;
use App\Events\InvoiceStatusChanged;
use App\Events\PaymentStatusChanged;
use App\Events\PurchaseOrderStatusChanged;
use App\Events\PurchaseRequestStatusChanged;
use App\Events\SupplierStatusChanged;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Support\ImportExecutionContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class TransactionNotificationObserver
{
    public function created(Model $model): void
    {
        if (ImportExecutionContext::suppressesNotifications()) {
            return;
        }

        if ($model instanceof GoodsReceipt && $model->status === GoodsReceiptStatus::PendingInspection) {
            $this->dispatchAfterCommit(new GoodsReceiptStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ));
        }
    }

    public function updated(Model $model): void
    {
        if (ImportExecutionContext::suppressesNotifications()) {
            return;
        }

        if (! $model->wasChanged('status')) {
            return;
        }

        $event = match (true) {
            $model instanceof Supplier => new SupplierStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            $model instanceof PurchaseRequest => new PurchaseRequestStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            $model instanceof PurchaseOrder => new PurchaseOrderStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            $model instanceof GoodsReceipt => new GoodsReceiptStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            $model instanceof Invoice => new InvoiceStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            $model instanceof Payment => new PaymentStatusChanged(
                $model->getKey(),
                $this->statusValue($model->status),
            ),
            default => null,
        };

        if ($event !== null) {
            $this->dispatchAfterCommit($event);
        }
    }

    private function dispatchAfterCommit(object $event): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(static fn () => Event::dispatch($event));

            return;
        }

        Event::dispatch($event);
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof BackedEnum
            ? (string) $status->value
            : (string) $status;
    }
}
