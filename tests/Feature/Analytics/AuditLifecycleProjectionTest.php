<?php

namespace Tests\Feature\Analytics;

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Analytics\AuditAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLifecycleProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_audit_entity_is_projected_to_business_stage_and_transaction(): void
    {
        $order = PurchaseOrder::query()->firstOrFail();
        $log = new AuditLog([
            'actor_id' => User::query()->firstOrFail()->getKey(),
            'event' => 'updated',
            'auditable_type' => (new PurchaseOrder)->getMorphClass(),
            'auditable_id' => $order->getKey(),
            'old_values' => [],
            'new_values' => ['status' => $order->status->value],
            'occurred_at' => now(),
        ]);

        $service = app(AuditAnalyticsService::class);

        $this->assertSame('2. PO', $service->businessStageLabel($log));
        $this->assertSame($order->number, $service->transactionReference($log));
        $this->assertContains(
            (new PurchaseOrder)->getMorphClass(),
            $service->morphTypesForStage(\App\Enums\BusinessFlowStage::PurchaseOrder),
        );
    }
}
