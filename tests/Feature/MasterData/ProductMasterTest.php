<?php

namespace Tests\Feature\MasterData;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductProcurementRule;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_linked_to_category_unit_and_procurement_rule(): void
    {
        $unit = Unit::query()->create([
            'code' => 'KG',
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $category = ProductCategory::query()->create([
            'code' => 'PROTEIN',
            'name' => 'Protein Hewani',
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'default_unit_id' => $unit->id,
            'code' => 'AYAM-BROILER',
            'name' => 'Ayam Broiler',
        ]);
        ProductProcurementRule::query()->create([
            'product_id' => $product->id,
            'quantity_tolerance_percentage' => 1.5,
            'requires_photo' => true,
            'requires_weight_photo' => true,
        ]);

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($product->defaultUnit->is($unit));
        $this->assertSame('1.50', $product->procurementRule->quantity_tolerance_percentage);
        $this->assertTrue($product->procurementRule->requires_weight_photo);
    }
}
