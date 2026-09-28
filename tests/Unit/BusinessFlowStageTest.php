<?php

namespace Tests\Unit;

use App\Enums\BusinessFlowStage;
use PHPUnit\Framework\TestCase;

class BusinessFlowStageTest extends TestCase
{
    public function test_procure_to_pay_has_exactly_six_ordered_stages(): void
    {
        $stages = BusinessFlowStage::cases();

        $this->assertCount(6, $stages);
        $this->assertSame(
            ['PR', 'PO', 'Delivery', 'Receiving', 'Invoice', 'Payment'],
            array_map(static fn (BusinessFlowStage $stage): string => $stage->label(), $stages),
        );
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            array_map(static fn (BusinessFlowStage $stage): int => $stage->order(), $stages),
        );
    }
}
