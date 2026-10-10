<?php

namespace Tests\Feature\Foundation;

use App\Actions\Auth\ManuallyVerifyPhoneAction;
use App\Enums\AccessScopeType;
use App\Enums\SystemPermission;
use App\Models\User;
use App\Models\UserAccessScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ManualUserPhoneAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_override_records_reason(): void
    {
        Permission::findOrCreate(SystemPermission::UserManage->value, 'web');
        $actor = User::factory()->create();
        $actor->givePermissionTo(SystemPermission::UserManage->value);
        UserAccessScope::query()->create([
            'user_id' => $actor->getKey(),
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);
        $target = User::factory()->create(['phone' => '081234567890', 'phone_verified_at' => null]);

        app(ManuallyVerifyPhoneAction::class)->execute($target, $actor, 'PIC dikonfirmasi langsung melalui administrasi.');

        $this->assertTrue($target->fresh()->hasVerifiedPhone());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->getKey(),
            'event' => 'phone_verified_manually',
        ]);
    }

    public function test_unprivileged_actor_cannot_verify_another_account(): void
    {
        $target = User::factory()->create(['phone' => '081234567890', 'phone_verified_at' => null]);

        $this->expectException(DomainException::class);
        app(ManuallyVerifyPhoneAction::class)->execute(
            $target,
            User::factory()->create(),
            'Tanpa kewenangan',
        );
    }
}
