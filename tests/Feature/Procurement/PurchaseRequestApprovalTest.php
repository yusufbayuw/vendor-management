<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\ApprovalDecisionSource;
use App\Enums\GovernanceProcess;
use App\Enums\OperationalProfile;
use App\Enums\PurchaseRequestStatus;
use App\Models\ApprovalAction;
use App\Models\GovernancePolicy;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Unit;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_lean_operation_can_submit_and_self_approve_purchase_request(): void
    {
        [$request, $requester] = $this->makePurchaseRequest(OperationalProfile::Lean);

        app(SubmitPurchaseRequestAction::class)->execute($request, $requester);

        $this->assertSame(PurchaseRequestStatus::Approved, $request->refresh()->status);
        $this->assertDatabaseHas('approval_actions', [
            'actor_id' => $requester->getKey(),
            'is_self_approval' => true,
            'decision_source' => ApprovalDecisionSource::AutoSelfApproval->value,
        ]);
    }

    public function test_strict_operation_rejects_self_approval(): void
    {
        [$request, $requester] = $this->makePurchaseRequest(OperationalProfile::Strict);

        app(SubmitPurchaseRequestAction::class)->execute($request, $requester);

        $this->expectException(DomainException::class);
        app(ApprovePurchaseRequestAction::class)->execute($request, $requester);
    }

    public function test_two_approvers_are_supported_when_policy_requires_it(): void
    {
        [$request, $requester, $organization] = $this->makePurchaseRequest(OperationalProfile::Standard, true);
        $approverA = User::factory()->create();
        $approverB = User::factory()->create();

        GovernancePolicy::query()->create([
            'organization_id' => $organization->id,
            'process' => GovernanceProcess::PurchaseRequestApproval,
            'self_approval_allowed' => false,
            'minimum_approvers' => 2,
        ]);

        app(SubmitPurchaseRequestAction::class)->execute($request, $requester);
        app(ApprovePurchaseRequestAction::class)->execute($request, $approverA);

        $this->assertSame(PurchaseRequestStatus::UnderReview, $request->refresh()->status);

        app(ApprovePurchaseRequestAction::class)->execute($request, $approverB);

        $this->assertSame(PurchaseRequestStatus::Approved, $request->refresh()->status);
    }

    /** @return array{PurchaseRequest, User, Organization} */
    private function makePurchaseRequest(OperationalProfile $profile, bool $returnOrganization = false): array
    {
        $requester = User::factory()->create();
        $organization = Organization::query()->create([
            'code' => fake()->unique()->bothify('ORG-###'),
            'name' => 'Organization',
            'operational_profile' => $profile,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->id,
            'code' => fake()->unique()->bothify('SPPG-###'),
            'name' => 'SPPG',
        ]);
        $unit = Unit::query()->create(['code' => fake()->unique()->lexify('U???'), 'name' => 'Kilogram']);
        $category = ProductCategory::query()->create(['code' => fake()->unique()->lexify('C???'), 'name' => 'Protein']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'default_unit_id' => $unit->id,
            'code' => fake()->unique()->lexify('P???'),
            'name' => 'Ayam',
        ]);
        $request = PurchaseRequest::query()->create([
            'number' => fake()->unique()->bothify('PR-#####'),
            'sppg_kitchen_id' => $kitchen->id,
            'requested_by' => $requester->id,
        ]);
        PurchaseRequestItem::query()->create([
            'purchase_request_id' => $request->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'requested_qty' => 100,
            'estimated_unit_price' => 40_000,
            'estimated_total' => 4_000_000,
        ]);

        return [$request, $requester, $organization];
    }
}
