<?php

namespace App\Services\Analytics;

use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Database\Eloquent\Builder;

class CommodityAnalyticsService
{
    public function __construct(private readonly UserAccessService $access) {}

    /**
     * @param  array{from?: string|null, to?: string|null, sppg_kitchen_id?: int|string|null, category_id?: int|string|null, product_id?: int|string|null, supplier_id?: int|string|null}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        $from = $filters['from'] ?? today()->subMonths(3)->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        $kitchenIds = $this->access->accessibleKitchenIds($user);

        return PurchaseOrderItem::query()
            ->with([
                'purchaseOrder.kitchen',
                'purchaseOrder.supplier',
                'product.category',
                'unit',
            ])
            ->whereHas('purchaseOrder', function (Builder $query) use ($kitchenIds, $from, $to, $filters): void {
                $query
                    ->whereIn('sppg_kitchen_id', $kitchenIds)
                    ->when($from, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date))
                    ->when($to, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date))
                    ->when(
                        $filters['sppg_kitchen_id'] ?? null,
                        fn (Builder $query, int|string $kitchenId): Builder => $query->where('sppg_kitchen_id', (int) $kitchenId),
                    )
                    ->when(
                        $filters['supplier_id'] ?? null,
                        fn (Builder $query, int|string $supplierId): Builder => $query->where('supplier_id', (int) $supplierId),
                    );
            })
            ->when(
                $filters['category_id'] ?? null,
                fn (Builder $query, int|string $categoryId): Builder => $query->whereHas(
                    'product',
                    fn (Builder $productQuery): Builder => $productQuery->where('category_id', (int) $categoryId),
                ),
            )
            ->when(
                $filters['product_id'] ?? null,
                fn (Builder $query, int|string $productId): Builder => $query->where('product_id', (int) $productId),
            );
    }
}
