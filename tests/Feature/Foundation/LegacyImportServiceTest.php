<?php

namespace Tests\Feature\Foundation;

use App\Enums\LegacyImportType;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Models\ApprovalRequest;
use App\Models\DataProvenance;
use App\Models\LegacyImportBatch;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Files\VendorFileStorage;
use App\Services\Imports\LegacyImportService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(VendorFileStorage::DISK);
    }

    public function test_existing_supplier_import_becomes_admin_managed_and_operational_without_portal_account(): void
    {
        [$organization, $actor] = $this->organizationAndActor();

        $path = 'legacy-imports/suppliers.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "code,legal_name,display_name,status\nSUP-LEG-001,PT Supplier Lama,Supplier Lama,active\n",
        );

        $batch = $this->batch($organization, $actor, LegacyImportType::Suppliers, $path);

        app(LegacyImportService::class)->execute($batch, $actor);

        $supplier = Supplier::query()->where('code', 'SUP-LEG-001')->firstOrFail();

        $this->assertSame(SupplierStatus::Active, $supplier->status);
        $this->assertSame(SupplierManagementMode::AdminManaged, $supplier->management_mode);
        $this->assertFalse($supplier->users()->exists());
        $this->assertTrue(app(SupplierOperationalEligibilityService::class)->isOperationallyEligible($supplier));
        $this->assertDatabaseHas('data_provenances', [
            'sourceable_type' => $supplier->getMorphClass(),
            'sourceable_id' => $supplier->getKey(),
            'legacy_import_batch_id' => $batch->getKey(),
            'source_row' => 2,
        ]);
        $this->assertSame(0, $supplier->users()->count());
        $this->assertSame('completed', $batch->refresh()->status);
    }

    public function test_approved_legacy_purchase_request_is_imported_without_replaying_approval_workflow(): void
    {
        [$organization, $actor] = $this->organizationAndActor();
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-LEG-001',
            'name' => 'Dapur Legacy',
        ]);
        $unit = Unit::query()->create([
            'code' => 'KG-LEG',
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $category = ProductCategory::query()->create([
            'code' => 'CAT-LEG',
            'name' => 'Legacy',
        ]);
        Product::query()->create([
            'category_id' => $category->getKey(),
            'default_unit_id' => $unit->getKey(),
            'code' => 'PROD-LEG-001',
            'name' => 'Produk Legacy',
        ]);

        $path = 'legacy-imports/pr.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "number,kitchen_code,status,product_code,unit_code,requested_qty,estimated_unit_price,approved_at\n".
            "PR-LEG-001,{$kitchen->code},approved,PROD-LEG-001,KG-LEG,100,40000,2026-08-01 10:00:00\n",
        );

        $batch = $this->batch($organization, $actor, LegacyImportType::PurchaseRequests, $path);

        app(LegacyImportService::class)->execute($batch, $actor);

        $request = PurchaseRequest::query()->where('number', 'PR-LEG-001')->firstOrFail();

        $this->assertSame(PurchaseRequestStatus::Approved, $request->status);
        $this->assertSame(1, $request->items()->count());
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertSame(1, DataProvenance::query()
            ->where('sourceable_type', $request->getMorphClass())
            ->where('sourceable_id', $request->getKey())
            ->count());
        $this->assertFalse((bool) data_get($batch->refresh()->summary, 'workflow_replayed', true));
    }

    private function organizationAndActor(): array
    {
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-LEG-###'),
            'name' => 'Legacy Organization',
            'operational_profile' => OperationalProfile::Lean,
        ]);

        return [$organization, User::factory()->create()];
    }

    private function batch(
        Organization $organization,
        User $actor,
        LegacyImportType $type,
        string $path,
    ): LegacyImportBatch {
        return LegacyImportBatch::query()->create([
            'organization_id' => $organization->getKey(),
            'import_type' => $type,
            'original_filename' => basename($path),
            'file_path' => $path,
            'status' => 'pending',
            'imported_by' => $actor->getKey(),
        ]);
    }
}
