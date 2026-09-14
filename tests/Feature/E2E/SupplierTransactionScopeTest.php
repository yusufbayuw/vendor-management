<?php

namespace Tests\Feature\E2E;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTransactionScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_ayam_supplier_pages_only_show_ayam_transactions(): void
    {
        [$ayamPo, $taniPo] = $this->supplierOrders();
        $ayamInvoice = $ayamPo->invoice()->firstOrFail();
        $taniInvoice = $taniPo->invoice()->firstOrFail();
        $ayamAdmin = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();

        $this->actingAs($ayamAdmin)
            ->get(route('filament.supplier.resources.purchase-orders.index'))
            ->assertOk()
            ->assertSee($ayamPo->number)
            ->assertDontSee($taniPo->number);

        $this->get(route('filament.supplier.resources.invoices.index'))
            ->assertOk()
            ->assertSee($ayamInvoice->number)
            ->assertDontSee($taniInvoice->number);
    }

    public function test_tani_supplier_pages_only_show_tani_transactions(): void
    {
        [$ayamPo, $taniPo] = $this->supplierOrders();
        $ayamInvoice = $ayamPo->invoice()->firstOrFail();
        $taniInvoice = $taniPo->invoice()->firstOrFail();
        $taniAdmin = User::query()->where('email', 'supplier.tani.admin@example.test')->firstOrFail();

        $this->actingAs($taniAdmin)
            ->get(route('filament.supplier.resources.purchase-orders.index'))
            ->assertOk()
            ->assertSee($taniPo->number)
            ->assertDontSee($ayamPo->number);

        $this->get(route('filament.supplier.resources.invoices.index'))
            ->assertOk()
            ->assertSee($taniInvoice->number)
            ->assertDontSee($ayamInvoice->number);
    }

    /** @return array{PurchaseOrder, PurchaseOrder} */
    private function supplierOrders(): array
    {
        $weekly = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();
        $ayam = Supplier::query()->where('code', 'SUP-AYAM')->firstOrFail();
        $tani = Supplier::query()->where('code', 'SUP-TANI')->firstOrFail();

        $ayamPo = PurchaseOrder::query()
            ->where('purchase_request_id', $weekly->getKey())
            ->where('supplier_id', $ayam->getKey())
            ->firstOrFail();
        $taniPo = PurchaseOrder::query()
            ->where('purchase_request_id', $weekly->getKey())
            ->where('supplier_id', $tani->getKey())
            ->firstOrFail();

        return [$ayamPo, $taniPo];
    }
}
