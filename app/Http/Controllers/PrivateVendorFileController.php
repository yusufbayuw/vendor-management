<?php

namespace App\Http\Controllers;

use App\Enums\SystemPermission;
use App\Models\DeliverySchedule;
use App\Models\GoodsReceiptAttachment;
use App\Models\Invoice;
use App\Models\PaymentAttachment;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditService;
use App\Services\Files\VendorFileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PrivateVendorFileController extends Controller
{
    public function __construct(
        private readonly VendorFileStorage $files,
        private readonly UserAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function supplierDocument(Request $request, SupplierDocument $supplierDocument): BinaryFileResponse
    {
        $user = $this->authenticatedUser($request);

        abort_unless(
            $this->ownsSupplier($user, $supplierDocument->supplier_id)
            || ($user->canAny([
                SystemPermission::SupplierView->value,
                SystemPermission::SupplierVerify->value,
                SystemPermission::AuditView->value,
            ]) && $this->access->canAccessSupplier($user, $supplierDocument->supplier_id)),
            403,
        );

        return $this->serve(
            $request,
            $supplierDocument,
            $supplierDocument->file_path,
            $this->filename($supplierDocument->file_path, 'dokumen-supplier'),
        );
    }

    public function goodsReceiptAttachment(Request $request, GoodsReceiptAttachment $goodsReceiptAttachment): BinaryFileResponse
    {
        $goodsReceiptAttachment->loadMissing('goodsReceipt');
        $receipt = $goodsReceiptAttachment->goodsReceipt;
        abort_if($receipt === null, 404);

        $this->authorizeOperationalFile(
            $request,
            $receipt->sppg_kitchen_id,
            $receipt->supplier_id,
            [
                SystemPermission::GoodsReceiptCreate,
                SystemPermission::GoodsReceiptInspect,
                SystemPermission::GoodsReceiptReject,
                SystemPermission::DeliveryManage,
                SystemPermission::AuditView,
                SystemPermission::ReportsView,
            ],
        );

        return $this->serve(
            $request,
            $goodsReceiptAttachment,
            $goodsReceiptAttachment->file_path,
            $this->filename($goodsReceiptAttachment->file_path, 'bukti-penerimaan'),
        );
    }

    public function paymentAttachment(Request $request, PaymentAttachment $paymentAttachment): BinaryFileResponse
    {
        $paymentAttachment->loadMissing('payment.invoice');
        $invoice = $paymentAttachment->payment?->invoice;
        abort_if($invoice === null, 404);

        $this->authorizeOperationalFile(
            $request,
            $invoice->sppg_kitchen_id,
            $invoice->supplier_id,
            [
                SystemPermission::PaymentCreate,
                SystemPermission::PaymentVerify,
                SystemPermission::InvoiceReview,
                SystemPermission::InvoiceApprove,
                SystemPermission::AuditView,
                SystemPermission::ReportsView,
            ],
        );

        return $this->serve(
            $request,
            $paymentAttachment,
            $paymentAttachment->file_path,
            $this->filename($paymentAttachment->file_path, 'bukti-pembayaran'),
        );
    }

    public function deliveryNote(Request $request, DeliverySchedule $deliverySchedule): BinaryFileResponse
    {
        $deliverySchedule->loadMissing('purchaseOrder');
        $order = $deliverySchedule->purchaseOrder;
        abort_if($order === null, 404);

        $this->authorizeOperationalFile(
            $request,
            $order->sppg_kitchen_id,
            $order->supplier_id,
            [
                SystemPermission::DeliverySchedule,
                SystemPermission::DeliveryManage,
                SystemPermission::GoodsReceiptCreate,
                SystemPermission::AuditView,
                SystemPermission::ReportsView,
            ],
        );

        return $this->serve(
            $request,
            $deliverySchedule,
            $deliverySchedule->delivery_note_file,
            $this->filename($deliverySchedule->delivery_note_file, 'surat-jalan'),
        );
    }

    public function invoice(Request $request, Invoice $invoice): BinaryFileResponse
    {
        $this->authorizeOperationalFile(
            $request,
            $invoice->sppg_kitchen_id,
            $invoice->supplier_id,
            [
                SystemPermission::InvoiceSubmit,
                SystemPermission::InvoiceReview,
                SystemPermission::InvoiceApprove,
                SystemPermission::PaymentCreate,
                SystemPermission::PaymentVerify,
                SystemPermission::AuditView,
                SystemPermission::ReportsView,
            ],
        );

        return $this->serve(
            $request,
            $invoice,
            $invoice->invoice_file,
            $this->filename($invoice->invoice_file, 'invoice'),
        );
    }

    /** @param array<int, SystemPermission> $permissions */
    private function authorizeOperationalFile(
        Request $request,
        int $kitchenId,
        int $supplierId,
        array $permissions,
    ): void {
        $user = $this->authenticatedUser($request);

        if ($this->ownsSupplier($user, $supplierId)) {
            return;
        }

        abort_unless(
            $this->access->canAccessKitchen($user, $kitchenId)
            && $user->canAny(array_map(
                static fn (SystemPermission $permission): string => $permission->value,
                $permissions,
            )),
            403,
        );
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }

    private function ownsSupplier(User $user, int $supplierId): bool
    {
        return $user->suppliers()
            ->whereKey($supplierId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    private function serve(
        Request $request,
        Model $model,
        ?string $path,
        string $filename,
    ): BinaryFileResponse {
        abort_unless($this->files->ensurePrivate($path), 404);
        abort_if(blank($path), 404);

        $mime = $this->files->mimeType($path);
        $download = $request->boolean('download') || ! $this->files->isInlinePreviewable($mime);
        $event = $download ? 'file_downloaded' : 'file_viewed';

        $this->audit->record($model, $event, [], [
            'filename' => $filename,
            'mime_type' => $mime,
            'size' => $this->files->size($path),
        ]);

        $headers = [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Type' => $mime,
        ];

        if ($download) {
            return response()->download(
                $this->files->absolutePath($path),
                $filename,
                $headers,
            );
        }

        $fallback = Str::ascii($filename);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '-', $fallback) ?: 'file';
        $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $filename,
            $fallback,
        );

        return response()->file($this->files->absolutePath($path), $headers);
    }

    private function filename(?string $path, string $fallback): string
    {
        $filename = basename((string) $path);
        $filename = str_replace(["\r", "\n"], '', $filename);

        return $filename !== '' && $filename !== '.' ? $filename : $fallback;
    }
}
