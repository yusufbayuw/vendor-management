<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseAllocationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GeneratePurchaseOrdersAction
{
    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /** @return Collection<int, PurchaseOrder> */
    public function execute(PurchaseRequest $purchaseRequest, User $actor): Collection
    {
        return DB::transaction(function () use ($purchaseRequest, $actor): Collection {
            $purchaseRequest = PurchaseRequest::query()
                ->with(['kitchen', 'items.product', 'items.unit'])
                ->lockForUpdate()
                ->findOrFail($purchaseRequest->getKey());

            if ($purchaseRequest->status === PurchaseRequestStatus::PoGenerated) {
                return $purchaseRequest->purchaseOrders()->with('items')->get();
            }

            if ($purchaseRequest->status !== PurchaseRequestStatus::FullyAllocated) {
                throw new DomainException('PO hanya dapat dibuat setelah seluruh item PR selesai dialokasikan.');
            }

            $allocations = PurchaseAllocation::query()
                ->whereHas('purchaseRequestItem', fn ($query) => $query->where('purchase_request_id', $purchaseRequest->getKey()))
                ->where('status', PurchaseAllocationStatus::Allocated->value)
                ->whereDoesntHave('purchaseOrderItem')
                ->with(['supplier', 'purchaseRequestItem.product', 'purchaseRequestItem.unit'])
                ->lockForUpdate()
                ->get();

            if ($allocations->isEmpty()) {
                $existing = $purchaseRequest->purchaseOrders()->with('items')->get();

                if ($existing->isNotEmpty()) {
                    $purchaseRequest->update(['status' => PurchaseRequestStatus::PoGenerated]);

                    return $existing;
                }

                throw new DomainException('Tidak ada alokasi yang dapat dibuat menjadi PO.');
            }

            foreach ($allocations->groupBy('supplier_id') as $supplierId => $supplierAllocations) {
                $subtotal = round((float) $supplierAllocations->sum(fn (PurchaseAllocation $allocation) => (float) $allocation->subtotal), 2);

                $purchaseOrder = PurchaseOrder::query()->firstOrCreate(
                    [
                        'purchase_request_id' => $purchaseRequest->getKey(),
                        'supplier_id' => $supplierId,
                    ],
                    [
                        'number' => $this->documentNumbers->next('PO', $purchaseRequest->kitchen),
                        'sppg_kitchen_id' => $purchaseRequest->sppg_kitchen_id,
                        'order_date' => today(),
                        'delivery_start' => $purchaseRequest->needed_from,
                        'delivery_end' => $purchaseRequest->needed_until,
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_amount' => 0,
                        'total_amount' => $subtotal,
                        'status' => PurchaseOrderStatus::Draft,
                        'revision_number' => 0,
                        'created_by' => $actor->getKey(),
                    ],
                );

                foreach ($supplierAllocations as $allocation) {
                    $requestItem = $allocation->purchaseRequestItem;

                    PurchaseOrderItem::query()->firstOrCreate(
                        ['purchase_allocation_id' => $allocation->getKey()],
                        [
                            'purchase_order_id' => $purchaseOrder->getKey(),
                            'purchase_request_item_id' => $requestItem->getKey(),
                            'product_id' => $requestItem->product_id,
                            'unit_id' => $requestItem->unit_id,
                            'product_name_snapshot' => $requestItem->product->name,
                            'description_snapshot' => $requestItem->quality_specification ?: $requestItem->description,
                            'unit_name_snapshot' => $requestItem->unit->symbol ?: $requestItem->unit->name,
                            'ordered_qty' => $allocation->allocated_qty,
                            'unit_price' => $allocation->unit_price,
                            'subtotal' => $allocation->subtotal,
                            'delivered_qty' => 0,
                            'accepted_qty' => 0,
                            'rejected_qty' => 0,
                        ],
                    );

                    $allocation->update(['status' => PurchaseAllocationStatus::PoGenerated]);
                }

                $purchaseOrder->forceFill([
                    'subtotal' => $purchaseOrder->items()->sum('subtotal'),
                    'total_amount' => $purchaseOrder->items()->sum('subtotal')
                        + (float) $purchaseOrder->tax_amount
                        - (float) $purchaseOrder->discount_amount,
                ])->save();
            }

            $purchaseRequest->update(['status' => PurchaseRequestStatus::PoGenerated]);

            return $purchaseRequest->purchaseOrders()->with('items')->get();
        }, 3);
    }
}
