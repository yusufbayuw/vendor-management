<?php

namespace App\Actions\Procurement;

use App\Enums\SystemPermission;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestTemplate;
use App\Models\User;
use App\Services\Access\UserAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePurchaseRequestTemplateFromRequestAction
{
    public function __construct(private readonly UserAccessService $access) {}

    public function execute(PurchaseRequest $source, User $actor, string $name): PurchaseRequestTemplate
    {
        if (! $actor->can(SystemPermission::PurchaseRequestSubmit->value)
            || ! $this->access->canAccessKitchen($actor, $source->sppg_kitchen_id)) {
            throw new DomainException('Anda tidak memiliki akses untuk membuat template dari PR ini.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new DomainException('Nama template wajib diisi.');
        }

        $source->loadMissing('items');

        if ($source->items->isEmpty()) {
            throw new DomainException('PR sumber tidak memiliki item yang dapat dijadikan template.');
        }

        if (PurchaseRequestTemplate::query()
            ->where('sppg_kitchen_id', $source->sppg_kitchen_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists()) {
            throw new DomainException('Template dengan nama yang sama sudah tersedia untuk dapur ini.');
        }

        return DB::transaction(function () use ($source, $actor, $name): PurchaseRequestTemplate {
            $template = PurchaseRequestTemplate::query()->create([
                'sppg_kitchen_id' => $source->sppg_kitchen_id,
                'created_by' => $actor->getKey(),
                'name' => $name,
                'description' => $source->description,
                'notes' => $source->notes,
                'is_active' => true,
            ]);

            foreach ($source->items->sortBy('id')->values() as $index => $item) {
                $template->items()->create([
                    'product_id' => $item->product_id,
                    'unit_id' => $item->unit_id,
                    'description' => $item->description,
                    'quality_specification' => $item->quality_specification,
                    'requested_qty' => $item->requested_qty,
                    'estimated_unit_price' => $item->estimated_unit_price,
                    'notes' => $item->notes,
                    'sort_order' => $index + 1,
                ]);
            }

            return $template->load(['items.product', 'items.unit', 'kitchen']);
        }, 3);
    }
}
