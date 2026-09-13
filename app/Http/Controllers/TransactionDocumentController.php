<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionDocumentController extends Controller
{
    public function purchaseOrder(Request $request, PurchaseOrder $purchaseOrder, UserAccessService $access): View
    {
        $this->authorizeTransaction($request->user(), $purchaseOrder->sppg_kitchen_id, $purchaseOrder->supplier_id, $access);

        $purchaseOrder->load([
            'supplier',
            'kitchen.organization',
            'items.product',
            'items.unit',
        ]);

        return view('documents.purchase-order', compact('purchaseOrder'));
    }

    public function goodsReceipt(Request $request, GoodsReceipt $goodsReceipt, UserAccessService $access): View
    {
        $this->authorizeTransaction($request->user(), $goodsReceipt->sppg_kitchen_id, $goodsReceipt->supplier_id, $access);

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

        return view('documents.goods-receipt', compact('goodsReceipt'));
    }

    public function invoice(Request $request, Invoice $invoice, UserAccessService $access): View
    {
        $this->authorizeTransaction($request->user(), $invoice->sppg_kitchen_id, $invoice->supplier_id, $access);

        $invoice->load([
            'purchaseOrder.items',
            'supplier',
            'kitchen.organization',
            'adjustments',
            'payments',
        ]);

        return view('documents.invoice', compact('invoice'));
    }

    private function authorizeTransaction(?User $user, int $kitchenId, int $supplierId, UserAccessService $access): void
    {
        abort_unless($user !== null, 403);

        if ($access->canAccessKitchen($user, $kitchenId)) {
            return;
        }

        abort_unless(
            $user->suppliers()
                ->where('suppliers.id', $supplierId)
                ->wherePivot('is_active', true)
                ->exists(),
            403,
        );
    }
}
