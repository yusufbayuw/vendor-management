<?php

namespace Tests\Feature\Foundation;

use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\AccessScopeType;
use App\Enums\SystemRole;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Services\Notifications\OperationalReminderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreE2EInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_scoped_report_export_requires_permission(): void
    {
        $bandung = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($bandung)
            ->get(route('reports.procurement.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get(route('reports.procurement.csv'))
            ->assertForbidden();
    }

    public function test_transaction_documents_honor_internal_and_supplier_scope(): void
    {
        $weekly = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();
        $chickenPo = PurchaseOrder::query()
            ->where('purchase_request_id', $weekly->getKey())
            ->whereHas('supplier', fn ($query) => $query->where('code', 'SUP-AYAM'))
            ->firstOrFail();

        $bandung = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $cimahi = User::query()->where('email', 'sppg.cimahi@example.test')->firstOrFail();
        $ayam = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();
        $tani = User::query()->where('email', 'supplier.tani@example.test')->firstOrFail();

        $this->actingAs($bandung)
            ->get(route('documents.purchase-orders.show', $chickenPo))
            ->assertOk()
            ->assertSee($chickenPo->number);

        $this->actingAs($cimahi)
            ->get(route('documents.purchase-orders.show', $chickenPo))
            ->assertForbidden();

        $this->actingAs($ayam)
            ->get(route('documents.purchase-orders.show', $chickenPo))
            ->assertOk();

        $this->actingAs($tani)
            ->get(route('documents.purchase-orders.show', $chickenPo))
            ->assertForbidden();

        $invoice = $chickenPo->invoice()->firstOrFail();

        $this->actingAs($ayam)
            ->get(route('documents.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_purchase_request_notification_is_limited_to_matching_scope(): void
    {
        $wrongScope = User::factory()->create([
            'name' => 'Manager Scope Cimahi',
            'email' => 'manager.cimahi.scope@example.test',
        ]);
        $wrongScope->assignRole(SystemRole::SppgManager->value);

        $cimahi = SppgKitchen::query()->where('code', 'SPPG-CMH-001')->firstOrFail();
        UserAccessScope::query()->create([
            'user_id' => $wrongScope->getKey(),
            'scope_type' => AccessScopeType::SppgKitchen,
            'scope_id' => $cimahi->getKey(),
            'is_primary' => true,
        ]);

        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $requester = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $product = Product::query()->where('code', 'BERAS-PREMIUM')->firstOrFail();
        $unit = Unit::query()->where('code', 'KG')->firstOrFail();

        $request = PurchaseRequest::query()->create([
            'number' => 'PR-NOTIFY-SCOPE-001',
            'sppg_kitchen_id' => $bandung->getKey(),
            'requested_by' => $requester->getKey(),
            'period_start' => today(),
            'period_end' => today()->addDays(6),
            'description' => 'PR untuk test scope notifikasi.',
        ]);
        PurchaseRequestItem::query()->create([
            'purchase_request_id' => $request->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => $product->name,
            'requested_qty' => 10,
            'estimated_unit_price' => 15_000,
            'estimated_total' => 150_000,
        ]);

        app(SubmitPurchaseRequestAction::class)->execute($request, $requester);

        $globalApprover = User::query()->where('email', 'pusat@example.test')->firstOrFail();
        $this->assertTrue($globalApprover->notifications->contains(
            fn ($notification): bool => data_get($notification->data, 'entity_id') === $request->getKey(),
        ));

        $wrongScope->refresh();
        $this->assertFalse($wrongScope->notifications->contains(
            fn ($notification): bool => data_get($notification->data, 'entity_id') === $request->getKey(),
        ));
    }

    public function test_operational_reminders_are_idempotent_within_the_same_day(): void
    {
        $service = app(OperationalReminderService::class);

        $first = array_sum($service->send());
        $second = array_sum($service->send());

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    public function test_seeded_goods_receipts_have_generated_qc_notifications(): void
    {
        $bandung = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->assertTrue($bandung->notifications->contains(
            fn ($notification): bool => data_get($notification->data, 'title') === 'Penerimaan barang menunggu QC',
        ));

        $this->assertTrue(Supplier::query()->where('code', 'SUP-AYAM')->exists());
    }
}
