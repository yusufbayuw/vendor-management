<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseAllocationAndOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_pr_with_three_suppliers_generates_three_purchase_orders(): void
    {
        [$request, $items, $actor] = $this->makeApprovedPurchaseRequest([500, 100, 300]);
        $suppliers = collect([
            $this->supplier('SUP-A'),
            $this->supplier('SUP-B'),
            $this->supplier('SUP-C'),
        ]);

        foreach ($items as $index => $item) {
            app(AllocatePurchaseRequestItemAction::class)->execute(
                $item,
                $suppliers[$index],
                (float) $item->requested_qty,
                [40_000, 35_000, 12_000][$index],
                $actor,
            );
        }

        $this->assertSame(PurchaseRequestStatus::FullyAllocated, $request->refresh()->status);

        $orders = app(GeneratePurchaseOrdersAction::class)->execute($request, $actor);

        $this->assertCount(3, $orders);
        $this->assertSame(3, $request->purchaseOrders()->count());
        $this->assertSame(PurchaseRequestStatus::PoGenerated, $request->refresh()->status);
    }

    public function test_single_pr_item_can_be_split_across_two_suppliers(): void
    {
        [$request, $items, $actor] = $this->makeApprovedPurchaseRequest([1000]);
        $item = $items->first();

        app(AllocatePurchaseRequestItemAction::class)->execute($item, $this->supplier('SUP-A'), 600, 40_000, $actor);
        $this->assertSame(PurchaseRequestStatus::PartiallyAllocated, $request->refresh()->status);

        app(AllocatePurchaseRequestItemAction::class)->execute($item, $this->supplier('SUP-B'), 400, 39_500, $actor);
        $this->assertSame(PurchaseRequestStatus::FullyAllocated, $request->refresh()->status);

        $orders = app(GeneratePurchaseOrdersAction::class)->execute($request, $actor);

        $this->assertCount(2, $orders);
        $this->assertSame(1000.0, (float) $orders->flatMap->items->sum('ordered_qty'));
    }

    public function test_over_allocation_is_rejected(): void
    {
        [, $items, $actor] = $this->makeApprovedPurchaseRequest([100]);
        $item = $items->first();
        $supplier = $this->supplier('SUP-A');

        app(AllocatePurchaseRequestItemAction::class)->execute($item, $supplier, 80, 40_000, $actor);

        $this->expectException(DomainException::class);
        app(AllocatePurchaseRequestItemAction::class)->execute($item, $supplier, 21, 40_000, $actor);
    }

    public function test_po_generation_is_idempotent(): void
    {
        [$request, $items, $actor] = $this->makeApprovedPurchaseRequest([100]);
        app(AllocatePurchaseRequestItemAction::class)->execute(
            $items->first(),
            $this->supplier('SUP-A'),
            100,
            40_000,
            $actor,
        );

        $first = app(GeneratePurchaseOrdersAction::class)->execute($request, $actor);
        $second = app(GeneratePurchaseOrdersAction::class)->execute($request->refresh(), $actor);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(1, $request->purchaseOrders()->count());
    }

    /** @return array{PurchaseRequest, Collection<int, PurchaseRequestItem>, User} */
    private function makeApprovedPurchaseRequest(array $quantities): array
    {
        $actor = User::factory()->create();
        $organization = Organization::query()->create(['code' => fake()->unique()->bothify('ORG-###'), 'name' => 'Organisasi']);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->id,
            'code' => fake()->unique()->bothify('SPPG-###'),
            'name' => 'Dapur SPPG',
        ]);
        $unit = Unit::query()->create(['code' => fake()->unique()->bothify('KG-###'), 'name' => 'Kilogram', 'symbol' => 'kg']);
        $category = ProductCategory::query()->create(['code' => fake()->unique()->bothify('CAT-###'), 'name' => 'Bahan']);
        $request = PurchaseRequest::query()->create([
            'number' => fake()->unique()->bothify('PR-#####'),
            'sppg_kitchen_id' => $kitchen->id,
            'requested_by' => $actor->id,
            'status' => PurchaseRequestStatus::Approved,
        ]);

        foreach ($quantities as $index => $quantity) {
            $product = Product::query()->create([
                'category_id' => $category->id,
                'default_unit_id' => $unit->id,
                'code' => fake()->unique()->bothify('P-####'),
                'name' => ['Ayam', 'Cabai', 'Gandum'][$index] ?? 'Produk '.($index + 1),
            ]);
            PurchaseRequestItem::query()->create([
                'purchase_request_id' => $request->id,
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'requested_qty' => $quantity,
            ]);
        }

        return [$request, $request->items()->get(), $actor];
    }

    private function supplier(string $code): Supplier
    {
        return Supplier::query()->create([
            'code' => $code,
            'legal_name' => 'Supplier '.$code,
            'email' => strtolower($code).'@example.test',
            'phone' => '08123456789',
            'status' => SupplierStatus::Active,
        ]);
    }
}
