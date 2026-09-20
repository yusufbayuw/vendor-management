<?php

namespace Tests\Feature\Foundation;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Models\FulfillmentDiscrepancy;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestTemplate;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_creates_role_scope_and_transaction_scenarios(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::query()->where('email', 'admin@example.test')->firstOrFail()->hasRole(SystemRole::SuperAdmin->value));

        $lean = User::query()->where('email', 'lean@example.test')->firstOrFail();
        $this->assertTrue($lean->hasRole(SystemRole::Requester->value));
        $this->assertTrue($lean->hasRole(SystemRole::FinanceManager->value));
        $this->assertTrue($lean->accessScopes()->where('scope_type', 'organization')->exists());

        $this->assertSame(
            SupplierStatus::Submitted,
            Supplier::query()->where('code', 'SUP-PENDING')->firstOrFail()->status,
        );
        $this->assertSame(
            SupplierStatus::Suspended,
            Supplier::query()->where('code', 'SUP-SUSPENDED')->firstOrFail()->status,
        );

        $weekly = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();
        $this->assertSame(PurchaseRequestStatus::PoGenerated, $weekly->status);
        $this->assertSame(3, $weekly->purchaseOrders()->count());

        $chickenPo = PurchaseOrder::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'SUP-AYAM'))
            ->where('purchase_request_id', $weekly->getKey())
            ->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::Closed, $chickenPo->status);
        $this->assertSame(InvoiceStatus::Paid, $chickenPo->invoice()->firstOrFail()->status);

        $chiliPo = PurchaseOrder::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'SUP-TANI'))
            ->where('purchase_request_id', $weekly->getKey())
            ->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::Invoiced, $chiliPo->status);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $chiliPo->invoice()->firstOrFail()->status);
        $this->assertSame((float) $chiliPo->total_amount, (float) $chiliPo->invoice()->firstOrFail()->po_amount);

        $ricePo = PurchaseOrder::query()
            ->whereHas('supplier', fn ($query) => $query->where('code', 'SUP-PANGAN'))
            ->where('purchase_request_id', $weekly->getKey())
            ->firstOrFail();
        $this->assertSame(PurchaseOrderStatus::PartiallyDelivered, $ricePo->status);

        $this->assertSame(
            PurchaseRequestStatus::Draft,
            PurchaseRequest::query()->where('number', 'PR-DEMO-DRAFT-001')->firstOrFail()->status,
        );
        $this->assertSame(
            PurchaseRequestStatus::Submitted,
            PurchaseRequest::query()->where('number', 'PR-DEMO-SUBMITTED-001')->firstOrFail()->status,
        );

        $leanRequest = PurchaseRequest::query()->where('number', 'PR-DEMO-LEAN-001')->firstOrFail();
        $this->assertSame(PurchaseRequestStatus::PoGenerated, $leanRequest->status);
        $this->assertSame($lean->getKey(), $leanRequest->requested_by);
        $this->assertSame($lean->getKey(), $leanRequest->approved_by);

        $this->assertGreaterThan(0, Invoice::query()->count());
        $historicalOrders = PurchaseOrder::query()
            ->where('number', 'like', 'PO-HIST-%')
            ->get();

        $this->assertCount(18, $historicalOrders);
        $this->assertGreaterThanOrEqual(
            9,
            $historicalOrders->pluck('order_date')->map(fn ($date): string => $date->format('Y-m'))->unique()->count(),
        );
        $this->assertTrue(Invoice::query()->where('number', 'like', 'INV-HIST-%')->where('status', InvoiceStatus::PartiallyPaid->value)->exists());
        $this->assertTrue(FulfillmentDiscrepancy::query()->whereHas('purchaseOrder', fn ($query) => $query->where('number', 'like', 'PO-HIST-%'))->exists());
        $this->assertGreaterThanOrEqual(2, PurchaseRequestTemplate::query()->count());
        $this->assertTrue(
            PurchaseRequestTemplate::query()
                ->where('name', 'Kebutuhan Mingguan - Protein & Pokok')
                ->whereHas('items')
                ->exists(),
        );
    }
}
