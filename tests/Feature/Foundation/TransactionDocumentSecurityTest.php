<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccessScopeType;
use App\Enums\SystemRole;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_user_without_document_permission_is_denied(): void
    {
        $this->seed(DatabaseSeeder::class);
        $po = PurchaseOrder::query()->firstOrFail();
        $user = User::factory()->create();

        UserAccessScope::query()->create([
            'user_id' => $user->getKey(),
            'scope_type' => AccessScopeType::SppgKitchen,
            'scope_id' => $po->sppg_kitchen_id,
        ]);

        $this->actingAs($user)
            ->get(route('documents.purchase-orders.show', $po))
            ->assertForbidden();

        $user->assignRole(SystemRole::Auditor->value);
        $this->actingAs($user)
            ->get(route('documents.purchase-orders.show', $po))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }
}
