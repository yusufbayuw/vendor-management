<?php

namespace App\Actions\Procurement;

use App\Enums\PurchaseRequestStatus;
use App\Enums\SystemPermission;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Documents\DocumentNumberService;
use DomainException;
use Illuminate\Support\Facades\DB;

class DuplicatePurchaseRequestAction
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly UserAccessService $access,
    ) {}

    public function execute(PurchaseRequest $source, User $actor): PurchaseRequest
    {
        if (! $actor->can(SystemPermission::PurchaseRequestSubmit->value)
            || ! $this->access->canAccessKitchen($actor, $source->sppg_kitchen_id)) {
            throw new DomainException('Anda tidak memiliki akses untuk membuat PR dari sumber ini.');
        }

        $source->loadMissing(['items', 'kitchen']);

        if ($source->items->isEmpty()) {
            throw new DomainException('PR sumber tidak memiliki item yang dapat disalin.');
        }

        return DB::transaction(function () use ($source, $actor): PurchaseRequest {
            $copy = PurchaseRequest::query()->create([
                'number' => $this->documentNumbers->next('PR', $source->kitchen),
                'sppg_kitchen_id' => $source->sppg_kitchen_id,
                'requested_by' => $actor->getKey(),
                'description' => $source->description,
                'notes' => $source->notes,
                'status' => PurchaseRequestStatus::Draft,
            ]);

            foreach ($source->items->sortBy('id') as $item) {
                $copy->items()->create([
                    'product_id' => $item->product_id,
                    'unit_id' => $item->unit_id,
                    'description' => $item->description,
                    'quality_specification' => $item->quality_specification,
                    'requested_qty' => $item->requested_qty,
                    'estimated_unit_price' => $item->estimated_unit_price,
                    'estimated_total' => filled($item->estimated_unit_price)
                        ? round((float) $item->requested_qty * (float) $item->estimated_unit_price, 2)
                        : null,
                    'preferred_delivery_date' => null,
                    'notes' => $item->notes,
                ]);
            }

            return $copy->load(['items.product', 'items.unit', 'kitchen']);
        }, 3);
    }
}
