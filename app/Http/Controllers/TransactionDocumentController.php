<?php

namespace App\Http\Controllers;

use App\Enums\SystemPermission;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TransactionDocumentController extends Controller
{
    public function purchaseOrder(Request $request, PurchaseOrder $purchaseOrder, UserAccessService $access): Response
    {
        $this->authorizeTransaction($request->user(), $purchaseOrder->sppg_kitchen_id, $purchaseOrder->supplier_id, $access, [SystemPermission::PurchaseOrderCreate, SystemPermission::PurchaseOrderApprove, SystemPermission::PurchaseOrderIssue, SystemPermission::DeliverySchedule, SystemPermission::AuditView, SystemPermission::ReportsView], [SystemPermission::PurchaseOrderAcknowledge, SystemPermission::DeliveryManage]);

        $purchaseOrder->load([
            'supplier',
            'kitchen.organization',
            'items.product',
            'items.unit',
        ]);

        return $this->privateView('documents.purchase-order', compact('purchaseOrder'));
    }

    public function goodsReceipt(Request $request, GoodsReceipt $goodsReceipt, UserAccessService $access): Response
    {
        $this->authorizeTransaction($request->user(), $goodsReceipt->sppg_kitchen_id, $goodsReceipt->supplier_id, $access, [SystemPermission::GoodsReceiptCreate, SystemPermission::GoodsReceiptInspect, SystemPermission::AuditView, SystemPermission::ReportsView], [SystemPermission::DeliveryManage]);

        $goodsReceipt->load([
            'purchaseOrder',
            'supplier',
            'kitchen.organization',
            'receiver',
            'inspector',
            'items.purchaseOrderItem',
            'attachments',
            'discrepancies',
        ]);

        return $this->privateView('documents.goods-receipt', compact('goodsReceipt'));
    }

    public function invoice(Request $request, Invoice $invoice, UserAccessService $access): Response
    {
        $this->authorizeTransaction($request->user(), $invoice->sppg_kitchen_id, $invoice->supplier_id, $access, [SystemPermission::InvoiceReview, SystemPermission::InvoiceApprove, SystemPermission::PaymentCreate, SystemPermission::PaymentVerify, SystemPermission::AuditView, SystemPermission::ReportsView], [SystemPermission::InvoiceSubmit]);

        $invoice->load([
            'purchaseOrder.items',
            'supplier',
            'kitchen.organization',
            'adjustments',
            'payments',
        ]);

        return $this->privateView('documents.invoice', compact('invoice'));
    }

    /**
     * @param  array<int, SystemPermission>  $internalPermissions
     * @param  array<int, SystemPermission>  $supplierPermissions
     */
    private function authorizeTransaction(
        ?User $user,
        int $kitchenId,
        int $supplierId,
        UserAccessService $access,
        array $internalPermissions,
        array $supplierPermissions,
    ): void {
        abort_unless($user !== null && $user->is_active, 403);

        $allowed = static fn (array $permissions): bool => $user->canAny(
            array_map(static fn (SystemPermission $permission): string => $permission->value, $permissions),
        );

        if ($access->canAccessKitchen($user, $kitchenId) && $allowed($internalPermissions)) {
            return;
        }

        abort_unless(
            $allowed($supplierPermissions)
                && $user->suppliers()
                    ->where('suppliers.id', $supplierId)
                    ->wherePivot('is_active', true)
                    ->exists(),
            403,
        );
    }

    private function privateView(string $view, array $data): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
