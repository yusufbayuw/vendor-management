<?php

namespace Tests\Feature\Analytics;

use App\Enums\GovernanceProcess;
use App\Models\ApprovalRequest;
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

    public function test_governance_approval_is_projected_to_its_actual_business_stage(): void
    {
        $order = PurchaseOrder::query()->firstOrFail();
        $request = $order->purchaseRequest()->firstOrFail();
        $actor = User::query()->firstOrFail();

        $approval = ApprovalRequest::query()->create([
            'organization_id' => $order->kitchen->organization_id,
            'approvable_type' => $request->getMorphClass(),
            'approvable_id' => $request->getKey(),
            'process' => GovernanceProcess::PurchaseRequestApproval,
            'status' => 'pending',
            'requested_by' => $actor->getKey(),
            'required_approvers' => 1,
            'self_approval_allowed' => false,
            'requires_override_reason' => false,
            'requested_at' => now(),
        ]);

        $log = new AuditLog([
            'actor_id' => $actor->getKey(),
            'event' => 'created',
            'auditable_type' => $approval->getMorphClass(),
            'auditable_id' => $approval->getKey(),
            'old_values' => [],
            'new_values' => [],
            'occurred_at' => now(),
        ]);

        $service = app(AuditAnalyticsService::class);

        $this->assertSame('1. PR', $service->businessStageLabel($log));
        $this->assertSame($request->number, $service->transactionReference($log));
    }
}
