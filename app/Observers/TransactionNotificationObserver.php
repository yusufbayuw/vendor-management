<?php

namespace App\Observers;

use App\Enums\GoodsReceiptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemPermission;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Database\Eloquent\Model;

class TransactionNotificationObserver
{
    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        match (true) {
            $model instanceof Supplier => $this->supplierUpdated($model),
            $model instanceof PurchaseRequest => $this->purchaseRequestUpdated($model),
            $model instanceof PurchaseOrder => $this->purchaseOrderUpdated($model),
            $model instanceof GoodsReceipt => $this->goodsReceiptUpdated($model),
            $model instanceof Invoice => $this->invoiceUpdated($model),
            $model instanceof Payment => $this->paymentUpdated($model),
            default => null,
        };
    }

    private function supplierUpdated(Supplier $supplier): void
    {
        if ($supplier->status === SupplierStatus::Submitted) {
            $this->notifications->toPermission(
                SystemPermission::SupplierVerify,
                'Supplier baru menunggu verifikasi',
                "{$supplier->display_name} telah mengajukan pendaftaran supplier.",
                Supplier::class,
                $supplier->getKey(),
                'warning',
                '/admin/suppliers',
            );

            return;
        }

        if (in_array($supplier->status, [SupplierStatus::Active, SupplierStatus::RevisionRequired, SupplierStatus::Rejected, SupplierStatus::Suspended], true)) {
            $this->notifications->toSupplier(
                $supplier,
                'Status supplier diperbarui',
                "Status {$supplier->display_name} sekarang: {$supplier->status->label()}.",
                Supplier::class,
                $supplier->getKey(),
                $supplier->status === SupplierStatus::Active ? 'success' : 'warning',
                '/supplier',
            );
        }
    }

    private function purchaseRequestUpdated(PurchaseRequest $request): void
    {
        $request->loadMissing('kitchen');

        if ($request->status === PurchaseRequestStatus::Submitted) {
            $this->notifications->toKitchenPermission(
                $request->kitchen,
                SystemPermission::PurchaseRequestApprove,
                'Purchase Request menunggu approval',
                "{$request->number} telah diajukan dan menunggu persetujuan.",
                PurchaseRequest::class,
                $request->getKey(),
                'warning',
                '/admin/purchase-requests',
            );
        }

        if ($request->status === PurchaseRequestStatus::Approved) {
            $this->notifications->toKitchenPermission(
                $request->kitchen,
                SystemPermission::PurchaseRequestAllocate,
                'Purchase Request disetujui',
                "{$request->number} siap dialokasikan ke supplier.",
                PurchaseRequest::class,
                $request->getKey(),
                'success',
                '/admin/purchase-requests',
            );
        }
    }

    private function purchaseOrderUpdated(PurchaseOrder $order): void
    {
        $order->loadMissing(['kitchen', 'supplier']);

        if ($order->status === PurchaseOrderStatus::Issued) {
            $this->notifications->toSupplier(
                $order->supplier,
                'Purchase Order baru diterbitkan',
                "{$order->number} telah diterbitkan dan menunggu konfirmasi supplier.",
                PurchaseOrder::class,
                $order->getKey(),
                'warning',
                '/supplier/purchase-orders',
            );
        }

        if ($order->status === PurchaseOrderStatus::Acknowledged) {
            $this->notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::DeliverySchedule,
                'PO dikonfirmasi supplier',
                "{$order->number} sudah dikonfirmasi supplier dan siap dijadwalkan.",
                PurchaseOrder::class,
                $order->getKey(),
                'success',
                '/admin/purchase-orders',
            );
        }

        if ($order->status === PurchaseOrderStatus::PendingExceptionClosure) {
            $this->notifications->toKitchenPermission(
                $order->kitchen,
                SystemPermission::PurchaseOrderExceptionClose,
                'PO menunggu penutupan exception',
                "{$order->number} memiliki selisih fulfillment yang menunggu keputusan.",
                PurchaseOrder::class,
                $order->getKey(),
                'warning',
                '/admin/purchase-orders',
            );
        }

        if (in_array($order->status, [PurchaseOrderStatus::Fulfilled, PurchaseOrderStatus::ClosedWithException], true)) {
            $this->notifications->toSupplier(
                $order->supplier,
                'PO siap ditagihkan',
                "{$order->number} sudah direkonsiliasi dan dapat dibuatkan invoice.",
                PurchaseOrder::class,
                $order->getKey(),
                'success',
                '/supplier/invoices',
            );
        }
    }

    private function goodsReceiptUpdated(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== GoodsReceiptStatus::PendingInspection) {
            return;
        }

        $receipt->loadMissing('kitchen');
        $this->notifications->toKitchenPermission(
            $receipt->kitchen,
            SystemPermission::GoodsReceiptInspect,
            'Penerimaan barang menunggu QC',
            "{$receipt->number} telah dicatat dan menunggu pemeriksaan kualitas.",
            GoodsReceipt::class,
            $receipt->getKey(),
            'warning',
            '/admin/goods-receipts',
        );
    }

    private function invoiceUpdated(Invoice $invoice): void
    {
        $invoice->loadMissing(['kitchen', 'supplier']);

        if ($invoice->status === InvoiceStatus::Submitted) {
            $this->notifications->toKitchenPermission(
                $invoice->kitchen,
                SystemPermission::InvoiceApprove,
                'Invoice menunggu approval',
                "{$invoice->number} telah diajukan supplier/finance.",
                Invoice::class,
                $invoice->getKey(),
                'warning',
                '/admin/invoices',
            );
        }

        if ($invoice->status === InvoiceStatus::Approved) {
            $this->notifications->toKitchenPermission(
                $invoice->kitchen,
                SystemPermission::PaymentCreate,
                'Invoice siap dibayar',
                "{$invoice->number} telah disetujui dan siap diproses pembayarannya.",
                Invoice::class,
                $invoice->getKey(),
                'success',
                '/admin/payments',
            );
        }
    }

    private function paymentUpdated(Payment $payment): void
    {
        $payment->loadMissing('invoice.kitchen', 'invoice.supplier');

        if ($payment->status === PaymentStatus::Submitted) {
            $this->notifications->toKitchenPermission(
                $payment->invoice->kitchen,
                SystemPermission::PaymentVerify,
                'Pembayaran menunggu verifikasi',
                "{$payment->number} telah diajukan untuk verifikasi.",
                Payment::class,
                $payment->getKey(),
                'warning',
                '/admin/payments',
            );
        }

        if ($payment->status === PaymentStatus::Verified) {
            $this->notifications->toSupplier(
                $payment->invoice->supplier,
                'Pembayaran telah diverifikasi',
                "Pembayaran {$payment->number} sebesar Rp ".number_format((float) $payment->amount, 0, ',', '.').' telah diverifikasi.',
                Payment::class,
                $payment->getKey(),
                'success',
                '/supplier/invoices',
            );
        }
    }
}
