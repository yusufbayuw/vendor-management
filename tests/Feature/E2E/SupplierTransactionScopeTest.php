<?php

namespace Tests\Feature\E2E;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SupplierStatus;
use App\Filament\Supplier\Resources\DeliverySchedules\DeliveryScheduleResource;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource;
use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\DeliverySchedule;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierPortalAccessService;
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

    public function test_business_suspended_supplier_can_read_only_own_transaction_history(): void
    {
        [$ayamPo, $taniPo] = $this->supplierOrders();
        $ayam = $ayamPo->supplier;
        $user = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();

        $ayamDelivery = DeliverySchedule::query()->create([
            'number' => 'DS-SUSPENDED-AYAM',
            'purchase_order_id' => $ayamPo->getKey(),
            'planned_delivery_at' => now()->addDay(),
            'status' => DeliveryScheduleStatus::Planned,
            'created_by' => $user->getKey(),
        ]);
        DeliverySchedule::query()->create([
            'number' => 'DS-OTHER-TANI',
            'purchase_order_id' => $taniPo->getKey(),
            'planned_delivery_at' => now()->addDay(),
            'status' => DeliveryScheduleStatus::Planned,
            'created_by' => $user->getKey(),
        ]);

        Payment::query()->create([
            'number' => 'PAY-SUSPENDED-OWN',
            'invoice_id' => $ayamPo->invoice()->firstOrFail()->getKey(),
            'payment_date' => today(),
            'amount' => 250_000,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Verified,
            'created_by' => $user->getKey(),
        ]);
        Payment::query()->create([
            'number' => 'PAY-OTHER-SUPPLIER',
            'invoice_id' => $taniPo->invoice()->firstOrFail()->getKey(),
            'payment_date' => today(),
            'amount' => 300_000,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Verified,
            'created_by' => $user->getKey(),
        ]);

        $ayam->forceFill(['status' => SupplierStatus::Suspended])->save();

        $access = app(SupplierPortalAccessService::class);
        $this->assertSame([$ayam->getKey()], $access->readableSupplierIds($user));
        $this->assertSame([], $access->activeSupplierIds($user));

        $this->actingAs($user)
            ->get(route('filament.supplier.resources.purchase-orders.index'))
            ->assertOk()
            ->assertSee($ayamPo->number)
            ->assertDontSee($taniPo->number);

        $this->get(route('filament.supplier.resources.invoices.index'))
            ->assertOk()
            ->assertSee($ayamPo->invoice()->firstOrFail()->number)
            ->assertDontSee($taniPo->invoice()->firstOrFail()->number)
            ->assertSee('PAY-SUSPENDED-OWN')
            ->assertDontSee('PAY-OTHER-SUPPLIER')
            ->assertDontSee('Buat Invoice');

        $this->get(route('filament.supplier.resources.delivery-schedules.index'))
            ->assertOk()
            ->assertSee($ayamDelivery->number)
            ->assertDontSee('DS-OTHER-TANI');

        // History queries remain scoped to ownership, while write eligibility is empty.
        $this->assertTrue(PurchaseOrderResource::getEloquentQuery()->whereKey($ayamPo)->exists());
        $this->assertFalse(PurchaseOrderResource::getEloquentQuery()->whereKey($taniPo)->exists());
        $this->assertTrue(DeliveryScheduleResource::getEloquentQuery()->whereKey($ayamDelivery)->exists());
        $this->assertTrue(InvoiceResource::getEloquentQuery()->whereKey($ayamPo->invoice)->exists());
        $this->assertSame([], InvoiceResource::supplierIds());
    }

    public function test_deactivated_user_and_revoked_supplier_membership_cannot_read_history(): void
    {
        [$ayamPo] = $this->supplierOrders();
        $user = User::query()->where('email', 'supplier.ayam.admin@example.test')->firstOrFail();
        $access = app(SupplierPortalAccessService::class);
        $ayamPo->supplier->forceFill(['status' => SupplierStatus::Suspended])->save();

        $user->forceFill(['is_active' => false])->save();
        $this->assertSame([], $access->readableSupplierIds($user->refresh()));

        $user->forceFill(['is_active' => true])->save();
        $user->suppliers()->updateExistingPivot($ayamPo->supplier_id, ['is_active' => false]);
        $this->assertSame([], $access->readableSupplierIds($user->refresh()));
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
