<?php

namespace Tests\Feature\Procurement;

use App\Actions\Procurement\CreatePurchaseRequestTemplateFromRequestAction;
use App\Actions\Procurement\DuplicatePurchaseRequestAction;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_requester_can_copy_accessible_purchase_request_into_fresh_draft(): void
    {
        $user = User::query()->where('email', 'role.requester@example.test')->firstOrFail();
        $source = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();

        $copy = app(DuplicatePurchaseRequestAction::class)->execute($source, $user);

        $this->assertSame(PurchaseRequestStatus::Draft, $copy->status);
        $this->assertSame($source->sppg_kitchen_id, $copy->sppg_kitchen_id);
        $this->assertSame($user->getKey(), $copy->requested_by);
        $this->assertNull($copy->period_start);
        $this->assertNull($copy->period_end);
        $this->assertNull($copy->needed_from);
        $this->assertNull($copy->needed_until);
        $this->assertSame($source->items()->count(), $copy->items()->count());
        $this->assertTrue($copy->items->every(fn ($item): bool => $item->preferred_delivery_date === null));
    }

    public function test_requester_can_save_accessible_purchase_request_as_template(): void
    {
        $user = User::query()->where('email', 'role.requester@example.test')->firstOrFail();
        $source = PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->firstOrFail();

        $template = app(CreatePurchaseRequestTemplateFromRequestAction::class)
            ->execute($source, $user, 'Kebutuhan Mingguan Bandung');

        $this->assertInstanceOf(PurchaseRequestTemplate::class, $template);
        $this->assertSame($source->sppg_kitchen_id, $template->sppg_kitchen_id);
        $this->assertSame($user->getKey(), $template->created_by);
        $this->assertSame($source->items()->count(), $template->items()->count());
        $this->assertTrue($template->is_active);
    }
}
