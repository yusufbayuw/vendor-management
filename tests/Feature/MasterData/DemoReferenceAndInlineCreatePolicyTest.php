<?php

namespace Tests\Feature\MasterData;

use App\Enums\SystemPermission;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SppgKitchen;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoReferenceAndInlineCreatePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_contains_public_reference_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Product::query()->where('code', 'REF-BPN-BERAS-MEDIUM')->exists());
        $this->assertTrue(Product::query()->where('code', 'REF-BPN-IKAN-KEMBUNG')->exists());

        $organization = Organization::query()->where('code', 'REF-BGN')->firstOrFail();

        $this->assertSame(
            3,
            SppgKitchen::query()->where('organization_id', $organization->getKey())->count(),
        );
        $this->assertTrue(SppgKitchen::query()->where('code', 'REF-SPPG-BDG-SUKALUYU')->exists());
    }

    public function test_inline_master_creation_permission_is_centralized(): void
    {
        $this->seed(DatabaseSeeder::class);

        $central = User::query()->where('email', 'role.central@example.test')->firstOrFail();
        $requester = User::query()->where('email', 'role.requester@example.test')->firstOrFail();

        $this->assertTrue($central->can(SystemPermission::MasterDataManage->value));
        $this->assertFalse($requester->can(SystemPermission::MasterDataManage->value));
    }
}
