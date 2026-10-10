<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccessScopeType;
use App\Enums\InvoiceStatus;
use App\Enums\SystemPermission;
use App\Enums\LegacyImportType;
use App\Enums\OperationalProfile;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierManagementMode;
use App\Enums\SupplierStatus;
use App\Models\ApprovalRequest;
use App\Models\DataProvenance;
use App\Models\Invoice;
use App\Models\LegacyImportBatch;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Jobs\ProcessLegacyImportBatch;
use App\Services\Imports\LegacyTabularReader;
use App\Services\Files\VendorFileStorage;
use App\Services\Imports\LegacyImportService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use ZipArchive;
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

    public function test_payment_can_be_imported_without_existing_invoice_po_or_pr(): void
    {
        [$organization, $actor] = $this->organizationAndActor();
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-DOWNSTREAM-001',
            'name' => 'Dapur Downstream',
        ]);
        Supplier::query()->create([
            'code' => 'SUP-DOWNSTREAM-001',
            'legal_name' => 'PT Supplier Downstream',
            'display_name' => 'Supplier Downstream',
            'status' => SupplierStatus::Active,
            'management_mode' => SupplierManagementMode::AdminManaged,
        ]);

        $path = 'legacy-imports/payment-downstream.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "number,payment_date,amount,payment_method,status,supplier_code,kitchen_code\n".
            "PAY-LEG-001,2026-08-25,18500000,bank_transfer,verified,SUP-DOWNSTREAM-001,{$kitchen->code}\n",
        );

        $batch = $this->batch($organization, $actor, LegacyImportType::Payments, $path);

        app(LegacyImportService::class)->execute($batch, $actor);

        $payment = Payment::query()->where('number', 'PAY-LEG-001')->firstOrFail();
        $invoice = Invoice::query()->where('number', 'LEGACY-INV-PAY-LEG-001')->firstOrFail();
        $purchaseOrder = PurchaseOrder::query()->where('number', 'LEGACY-PO-PAY-LEG-001')->firstOrFail();
        $request = PurchaseRequest::query()->where('number', 'LEGACY-PR-PAY-LEG-001')->firstOrFail();

        $this->assertSame(PaymentStatus::Verified, $payment->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(PurchaseOrderStatus::Closed, $purchaseOrder->status);
        $this->assertSame(PurchaseRequestStatus::PoGenerated, $request->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertGreaterThanOrEqual(3, DataProvenance::query()
            ->where('legacy_import_batch_id', $batch->getKey())
            ->where('provenance_type', 'legacy_import_synthetic')
            ->count());
        $this->assertGreaterThanOrEqual(
            3,
            (int) data_get($batch->refresh()->summary, 'synthetic_upstream_records', 0),
        );
        $this->assertFalse((bool) data_get($batch->summary, 'workflow_replayed', true));
    }

    public function test_import_never_overwrites_document_from_another_organization(): void
    {
        [$firstOrganization, $actor] = $this->organizationAndActor();
        [$otherOrganization] = $this->organizationAndActor();
        $originalKitchen = SppgKitchen::query()->create([
            'organization_id' => $firstOrganization->getKey(),
            'code' => 'SPPG-FIRST',
            'name' => 'Dapur Pertama',
        ]);
        $importKitchen = SppgKitchen::query()->create([
            'organization_id' => $otherOrganization->getKey(),
            'code' => 'SPPG-OTHER',
            'name' => 'Dapur Kedua',
        ]);
        $existing = PurchaseRequest::query()->create([
            'number' => 'PR-CROSS-ORG-001',
            'sppg_kitchen_id' => $originalKitchen->getKey(),
            'requested_by' => $actor->getKey(),
            'status' => PurchaseRequestStatus::Draft,
            'description' => 'Original and untouched',
        ]);

        $path = 'legacy-imports/cross-org.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "number,kitchen_code,status,description\nPR-CROSS-ORG-001,{$importKitchen->code},approved,Should not overwrite\n",
        );
        $batch = $this->batch($otherOrganization, $actor, LegacyImportType::PurchaseRequests, $path);

        app(LegacyImportService::class)->execute($batch, $actor);

        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->failed_rows);
        $this->assertSame('Original and untouched', $existing->fresh()->description);
        $this->assertSame($originalKitchen->getKey(), $existing->fresh()->sppg_kitchen_id);
    }

    public function test_preview_rolls_back_supplier_and_provenance_writes(): void
    {
        [$organization, $actor] = $this->organizationAndActor();

        $path = 'legacy-imports/preview-suppliers.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "code,legal_name,status\nSUP-PREVIEW-001,Supplier Simulasi,active\n",
        );

        $batch = $this->batch($organization, $actor, LegacyImportType::Suppliers, $path);
        $batch->forceFill(['dry_run' => true])->save();

        app(LegacyImportService::class)->execute($batch, $actor);

        $this->assertSame('preview_completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->imported_rows);
        $this->assertTrue((bool) data_get($batch->summary, 'dry_run'));
        $this->assertDatabaseMissing('suppliers', ['code' => 'SUP-PREVIEW-001']);
        $this->assertSame(0, DataProvenance::query()->where('legacy_import_batch_id', $batch->getKey())->count());
    }

    public function test_preview_preserves_dependent_rows_but_leaves_no_synthetic_records(): void
    {
        [$organization, $actor] = $this->organizationAndActor();

        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => 'SPPG-PREVIEW',
            'name' => 'Dapur Preview',
        ]);
        Supplier::query()->create([
            'code' => 'SUP-PREVIEW',
            'legal_name' => 'Supplier Preview',
        ]);

        $path = 'legacy-imports/preview-downstream.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "number,payment_date,amount,payment_method,status,supplier_code,kitchen_code\n".
            "PAY-PREVIEW-001,2026-08-25,250000,cash,verified,SUP-PREVIEW,{$kitchen->code}\n",
        );

        $batch = $this->batch($organization, $actor, LegacyImportType::Payments, $path);
        $batch->forceFill(['dry_run' => true])->save();
        app(LegacyImportService::class)->execute($batch, $actor);

        $this->assertSame('preview_completed', $batch->refresh()->status);
        $this->assertSame(1, $batch->imported_rows);
        $this->assertGreaterThanOrEqual(3, (int) data_get($batch->summary, 'synthetic_upstream_records', 0));
        $this->assertDatabaseMissing('payments', ['number' => 'PAY-PREVIEW-001']);
        $this->assertDatabaseMissing('invoices', ['number' => 'LEGACY-INV-PAY-PREVIEW-001']);
        $this->assertDatabaseMissing('purchase_orders', ['number' => 'LEGACY-PO-PAY-PREVIEW-001']);
    }

    public function test_import_job_requires_authorized_active_operator_and_can_complete_real_import(): void
    {
        [$organization, $actor] = $this->organizationAndActor();

        $path = 'legacy-imports/queued-suppliers.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "code,legal_name,status\nSUP-QUEUE-001,Supplier Queued,active\n",
        );
        $batch = $this->batch($organization, $actor, LegacyImportType::Suppliers, $path);
        $batch->forceFill(['status' => 'queued'])->save();

        $job = new ProcessLegacyImportBatch($batch->getKey());

        try {
            $job->handle(app(LegacyImportService::class), app(\App\Services\Access\UserAccessService::class));
            $this->fail('Unprivileged import operator must be denied.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('permission', $e->getMessage());
        }

        $actor->givePermissionTo(Permission::findOrCreate(SystemPermission::LegacyImportManage->value, 'web'));
        UserAccessScope::query()->create([
            'user_id' => $actor->getKey(),
            'scope_type' => AccessScopeType::Organization,
            'scope_id' => $organization->getKey(),
        ]);

        $job->handle(app(LegacyImportService::class), app(\App\Services\Access\UserAccessService::class));

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertDatabaseHas('suppliers', ['code' => 'SUP-QUEUE-001']);
    }

    public function test_csv_can_be_streamed_without_building_an_array_of_all_rows(): void
    {
        $path = 'legacy-imports/stream.csv';
        Storage::disk(VendorFileStorage::DISK)->put(
            $path,
            "number,status\n".implode('', array_map(
                static fn (int $index): string => "PR-STREAM-{$index},approved\n",
                range(1, 1500),
            )),
        );

        $reader = app(LegacyTabularReader::class);
        $stream = $reader->streamRows(
            Storage::disk(VendorFileStorage::DISK)->path($path),
            basename($path),
        );

        $this->assertSame(2, $stream->current()['_source_row']);
        $count = 0;
        foreach ($stream as $row) {
            $count++;
        }

        $this->assertSame(1500, $count);
    }

    public function test_xlsx_reads_rows_by_streaming_xml(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable.');
        }

        $path = Storage::disk(VendorFileStorage::DISK)->path('legacy-imports/stream.xlsx');
        @mkdir(dirname($path), 0775, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.
            '<row r="1"><c r="A1" t="inlineStr"><is><t>code</t></is></c><c r="B1" t="inlineStr"><is><t>legal_name</t></is></c></row>'.
            '<row r="2"><c r="A2" t="inlineStr"><is><t>SUP-STREAM-XLSX</t></is></c><c r="B2" t="inlineStr"><is><t>Supplier XLSX</t></is></c></row>'.
            '</sheetData></worksheet>');
        $zip->close();

        $rows = iterator_to_array(app(LegacyTabularReader::class)->streamRows($path, 'stream.xlsx'));

        $this->assertCount(1, $rows);
        $this->assertSame('SUP-STREAM-XLSX', $rows[0]['code']);
        $this->assertSame(2, $rows[0]['_source_row']);
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
