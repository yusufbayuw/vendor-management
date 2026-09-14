<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestFormUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_requester_sees_default_unit_guidance_on_purchase_request_form(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'role.requester@example.test')->firstOrFail();

        $this->actingAs($user)
            ->get('/admin/purchase-requests/create')
            ->assertOk()
            ->assertSee('Otomatis mengikuti satuan default produk. Dapat diubah bila kebutuhan menggunakan satuan lain.');
    }
}
