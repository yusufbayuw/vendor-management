<?php

namespace App\Services\Usability;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
use App\Models\DeliverySchedule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;

class WorkflowGuidanceService
{
    public function purchaseRequest(PurchaseRequest $request, ?User $user): string
    {
        return match ($request->status) {
            PurchaseRequestStatus::Draft => $this->can($user, SystemPermission::PurchaseRequestSubmit)
                ? 'Lengkapi lalu ajukan PR'
                : 'Menunggu pemohon mengajukan PR',
            PurchaseRequestStatus::Submitted, PurchaseRequestStatus::UnderReview => $this->can($user, SystemPermission::PurchaseRequestApprove)
                ? 'Periksa lalu setujui atau tolak'
                : 'Menunggu keputusan approval',
            PurchaseRequestStatus::Approved, PurchaseRequestStatus::PartiallyAllocated => $this->can($user, SystemPermission::PurchaseRequestAllocate)
                ? 'Alokasikan supplier'
                : 'Menunggu alokasi supplier',
            PurchaseRequestStatus::FullyAllocated => $this->can($user, SystemPermission::PurchaseOrderCreate)
                ? 'Generate Purchase Order'
                : 'Menunggu pembuatan PO',
            PurchaseRequestStatus::PoGenerated => 'Pantau Purchase Order',
            PurchaseRequestStatus::Closed => 'Selesai',
            PurchaseRequestStatus::Rejected => 'Ditolak — tinjau alasan',
            PurchaseRequestStatus::Cancelled => 'Dibatalkan',
        };
    }

    public function internalPurchaseOrder(PurchaseOrder $order, ?User $user): string
    {
        return match ($order->status) {
            PurchaseOrderStatus::Draft => $this->can($user, SystemPermission::PurchaseOrderCreate)
                ? 'Ajukan PO untuk approval'
                : 'Menunggu pengajuan approval',
            PurchaseOrderStatus::PendingApproval => $this->can($user, SystemPermission::PurchaseOrderApprove)
                ? 'Periksa lalu setujui PO'
                : 'Menunggu approval PO',
            PurchaseOrderStatus::Approved => $this->can($user, SystemPermission::PurchaseOrderIssue)
                ? 'Terbitkan PO ke supplier'
                : 'Menunggu penerbitan PO',
            PurchaseOrderStatus::Issued => 'Menunggu konfirmasi supplier',
            PurchaseOrderStatus::Acknowledged => $this->can($user, SystemPermission::DeliverySchedule)
                ? 'Jadwalkan pengiriman'
                : 'Menunggu jadwal pengiriman',
            PurchaseOrderStatus::Scheduled => 'Pantau pengiriman dan penerimaan',
            PurchaseOrderStatus::PartiallyDelivered => $this->can($user, SystemPermission::PurchaseOrderExceptionClose)
                ? 'Lanjutkan pengiriman atau proses exception'
                : 'Pantau sisa pengiriman',
            PurchaseOrderStatus::PendingExceptionClosure => $this->can($user, SystemPermission::PurchaseOrderExceptionClose)
                ? 'Proses close with exception'
                : 'Menunggu keputusan exception',
            PurchaseOrderStatus::Fulfilled, PurchaseOrderStatus::ClosedWithException => $this->can($user, SystemPermission::InvoiceReview)
                || $this->can($user, SystemPermission::InvoiceSubmit)
                    ? 'Generate invoice dari PO'
                    : 'Siap diproses menjadi invoice',
            PurchaseOrderStatus::Invoiced => 'Pantau invoice dan pembayaran',
            PurchaseOrderStatus::Paid => 'Pembayaran selesai — tutup proses',
            PurchaseOrderStatus::Closed => 'Selesai',
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
        };
    }

    public function supplierPurchaseOrder(PurchaseOrder $order, ?User $user): string
    {
        return match ($order->status) {
            PurchaseOrderStatus::Issued => $this->can($user, SystemPermission::PurchaseOrderAcknowledge)
                ? 'Konfirmasi Purchase Order'
                : 'Menunggu konfirmasi supplier',
            PurchaseOrderStatus::Acknowledged => $this->can($user, SystemPermission::DeliveryManage)
                ? 'Buat jadwal pengiriman'
                : 'Menunggu jadwal pengiriman',
            PurchaseOrderStatus::Scheduled => 'Siapkan pengiriman sesuai jadwal',
            PurchaseOrderStatus::PartiallyDelivered => $this->can($user, SystemPermission::DeliveryManage)
                ? 'Lanjutkan sisa pengiriman'
                : 'Pantau sisa pengiriman',
            PurchaseOrderStatus::PendingExceptionClosure => 'Menunggu penyelesaian selisih',
            PurchaseOrderStatus::Fulfilled, PurchaseOrderStatus::ClosedWithException => 'Menunggu invoice tersedia / diproses',
            PurchaseOrderStatus::Invoiced => 'Pantau status invoice',
            PurchaseOrderStatus::Paid => 'Pembayaran selesai',
            PurchaseOrderStatus::Closed => 'Selesai',
            PurchaseOrderStatus::Cancelled => 'Dibatalkan',
            default => 'Pantau status PO',
        };
    }

    public function supplierDelivery(DeliverySchedule $schedule, ?User $user): string
    {
        return match ($schedule->status) {
            DeliveryScheduleStatus::Draft, DeliveryScheduleStatus::Planned => $this->can($user, SystemPermission::DeliveryManage)
                ? 'Konfirmasi jadwal pengiriman'
                : 'Menunggu konfirmasi jadwal',
            DeliveryScheduleStatus::Confirmed => $this->can($user, SystemPermission::DeliveryManage)
                ? 'Isi kendaraan lalu tandai berangkat'
                : 'Menunggu keberangkatan',
            DeliveryScheduleStatus::InTransit => 'Dalam perjalanan — pantau penerimaan',
            DeliveryScheduleStatus::Arrived, DeliveryScheduleStatus::PartiallyReceived => 'Menunggu penerimaan / QC selesai',
            DeliveryScheduleStatus::Received => 'Pengiriman selesai diterima',
            DeliveryScheduleStatus::Missed => 'Jadwal terlewat — koordinasikan ulang',
            DeliveryScheduleStatus::Cancelled => 'Dibatalkan',
        };
    }

    public function supplierInvoice(Invoice $invoice, ?User $user): string
    {
        return match ($invoice->status) {
            InvoiceStatus::Draft => $this->can($user, SystemPermission::InvoiceSubmit)
                ? (blank($invoice->invoice_file) ? 'Upload file invoice lalu ajukan' : 'Ajukan invoice')
                : 'Menunggu pengajuan invoice',
            InvoiceStatus::Submitted, InvoiceStatus::UnderReview => 'Menunggu review / approval',
            InvoiceStatus::Approved => 'Disetujui — menunggu pembayaran',
            InvoiceStatus::PartiallyPaid => 'Pembayaran sebagian — pantau sisa',
            InvoiceStatus::Paid => 'Lunas',
            InvoiceStatus::Rejected => 'Ditolak — tinjau catatan',
            InvoiceStatus::Cancelled => 'Dibatalkan',
        };
    }

    private function can(?User $user, SystemPermission $permission): bool
    {
        return $user?->can($permission->value) ?? false;
    }
}
