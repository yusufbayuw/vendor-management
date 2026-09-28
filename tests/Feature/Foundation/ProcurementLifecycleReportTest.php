<?php

namespace Tests\Feature\Foundation;

use App\Enums\BusinessFlowStage;
use App\Models\User;
use App\Services\Reporting\ProcurementReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementLifecycleReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_procurement_report_exposes_six_stage_flow_summary(): void
    {
        $user = User::query()->where('email', 'sppg.bandung@example.test')->firstOrFail();

        $flow = app(ProcurementReportService::class)->flowSummary(
            $user,
            today()->subYear(),
            today()->addDay(),
        );

        $this->assertSame(
            array_map(static fn (BusinessFlowStage $stage): string => $stage->value, BusinessFlowStage::cases()),
            array_keys($flow),
        );

        foreach (BusinessFlowStage::cases() as $stage) {
            $this->assertSame($stage->order(), $flow[$stage->value]['order']);
            $this->assertArrayHasKey('wip', $flow[$stage->value]);
            $this->assertArrayHasKey('completed', $flow[$stage->value]);
            $this->assertArrayHasKey('avg_cycle_hours', $flow[$stage->value]);
        }
    }
}
