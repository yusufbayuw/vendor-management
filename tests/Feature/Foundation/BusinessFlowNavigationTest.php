<?php

namespace Tests\Feature\Foundation;

use App\Filament\Admin\Resources\DeliverySchedules\DeliveryScheduleResource as AdminDeliveryResource;
use App\Filament\Admin\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource as AdminInvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\PurchaseOrders\PurchaseOrderResource as AdminPurchaseOrderResource;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Supplier\Resources\DeliverySchedules\DeliveryScheduleResource as SupplierDeliveryResource;
use App\Filament\Supplier\Resources\Invoices\InvoiceResource as SupplierInvoiceResource;
use App\Filament\Supplier\Resources\PurchaseOrders\PurchaseOrderResource as SupplierPurchaseOrderResource;
use Tests\TestCase;

class BusinessFlowNavigationTest extends TestCase
{
    public function test_admin_transaction_navigation_uses_six_canonical_stages(): void
    {
        $this->assertSame('1. PR', PurchaseRequestResource::getNavigationLabel());
        $this->assertSame('2. PO', AdminPurchaseOrderResource::getNavigationLabel());
        $this->assertSame('3. Delivery', AdminDeliveryResource::getNavigationLabel());
        $this->assertSame('4. Receiving', GoodsReceiptResource::getNavigationLabel());
        $this->assertSame('5. Invoice', AdminInvoiceResource::getNavigationLabel());
        $this->assertSame('6. Payment', PaymentResource::getNavigationLabel());

        foreach ([
            PurchaseRequestResource::class,
            AdminPurchaseOrderResource::class,
            AdminDeliveryResource::class,
            GoodsReceiptResource::class,
            AdminInvoiceResource::class,
            PaymentResource::class,
        ] as $resource) {
            $this->assertSame('Alur Transaksi', $resource::getNavigationGroup());
        }
    }

    public function test_supplier_navigation_preserves_same_stage_numbers(): void
    {
        $this->assertSame('2. PO', SupplierPurchaseOrderResource::getNavigationLabel());
        $this->assertSame('3. Delivery', SupplierDeliveryResource::getNavigationLabel());
        $this->assertSame('5. Invoice', SupplierInvoiceResource::getNavigationLabel());

        foreach ([
            SupplierPurchaseOrderResource::class,
            SupplierDeliveryResource::class,
            SupplierInvoiceResource::class,
        ] as $resource) {
            $this->assertSame('Alur Transaksi', $resource::getNavigationGroup());
        }
    }
}
