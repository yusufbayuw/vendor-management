<?php

namespace Tests\Feature\Analytics;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Services\Analytics\GovernanceAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_user_can_open_governance_analytics_page(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/analytics/governance')
            ->assertOk()
            ->assertSee('Governance Analytics')
            ->assertSee('Approval & Governance Performance')
            ->assertSee('Decision Time')
            ->assertSee('Self Approval')
            ->assertSee('Export CSV / XLSX');
    }

    public function test_supplier_cannot_open_internal_governance_analytics_page(): void
    {
        $supplier = User::query()->where('email', 'supplier.ayam@example.test')->firstOrFail();

        $this->actingAs($supplier)
            ->get('/admin/analytics/governance')
            ->assertForbidden();
    }

    public function test_kitchen_scoped_user_only_sees_governance_records_for_their_kitchen(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();
        $bandung = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $records = app(GovernanceAnalyticsService::class)->query($user)->get();

        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $approvable = $record->approvable;

            $kitchenId = match (true) {
                $approvable instanceof PurchaseRequest => $approvable->sppg_kitchen_id,
                $approvable instanceof PurchaseOrder => $approvable->sppg_kitchen_id,
                $approvable instanceof Invoice => $approvable->sppg_kitchen_id,
                $approvable instanceof Payment => $approvable->invoice->sppg_kitchen_id,
                default => null,
            };

            $this->assertNotNull($kitchenId, 'Kitchen-scoped user must not receive organization-only approval records.');
            $this->assertSame((int) $bandung->getKey(), (int) $kitchenId);
        }
    }

    public function test_decision_time_uses_requested_and_resolved_timestamps(): void
    {
        $request = ApprovalRequest::query()->firstOrFail();
        $base = Carbon::parse('2026-09-01 08:00:00');

        $request->forceFill([
            'status' => ApprovalStatus::Approved,
            'requested_at' => $base->copy(),
            'resolved_at' => $base->copy()->addHours(3),
        ])->save();

        $request->refresh();

        $this->assertSame(3.0, app(GovernanceAnalyticsService::class)->decisionHours($request));
    }
}
