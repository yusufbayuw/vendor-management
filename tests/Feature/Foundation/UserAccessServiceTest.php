<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccessScopeType;
use App\Models\Organization;
use App\Models\SppgKitchen;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Services\Access\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_can_access_any_kitchen(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['code' => 'ORG-1', 'name' => 'Organisasi 1']);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $organization->id,
            'code' => 'SPPG-1',
            'name' => 'SPPG 1',
        ]);

        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);

        $this->assertTrue(app(UserAccessService::class)->canAccessKitchen($user, $kitchen));
    }

    public function test_organization_scope_only_accesses_kitchens_in_that_organization(): void
    {
        $user = User::factory()->create();
        $organizationA = Organization::query()->create(['code' => 'ORG-A', 'name' => 'Organisasi A']);
        $organizationB = Organization::query()->create(['code' => 'ORG-B', 'name' => 'Organisasi B']);
        $kitchenA = SppgKitchen::query()->create(['organization_id' => $organizationA->id, 'code' => 'A-1', 'name' => 'A 1']);
        $kitchenB = SppgKitchen::query()->create(['organization_id' => $organizationB->id, 'code' => 'B-1', 'name' => 'B 1']);

        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => AccessScopeType::Organization,
            'scope_id' => $organizationA->id,
        ]);

        $service = app(UserAccessService::class);

        $this->assertTrue($service->canAccessKitchen($user, $kitchenA));
        $this->assertFalse($service->canAccessKitchen($user, $kitchenB));
    }

    public function test_kitchen_scope_does_not_leak_to_another_kitchen(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['code' => 'ORG', 'name' => 'Organisasi']);
        $kitchenA = SppgKitchen::query()->create(['organization_id' => $organization->id, 'code' => 'A', 'name' => 'A']);
        $kitchenB = SppgKitchen::query()->create(['organization_id' => $organization->id, 'code' => 'B', 'name' => 'B']);

        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => AccessScopeType::SppgKitchen,
            'scope_id' => $kitchenA->id,
        ]);

        $service = app(UserAccessService::class);

        $this->assertTrue($service->canAccessKitchen($user, $kitchenA));
        $this->assertFalse($service->canAccessKitchen($user, $kitchenB));
    }
}
