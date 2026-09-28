<?php

namespace Tests\Feature\Foundation;

use App\Enums\DeliveryScheduleStatus;
use App\Enums\PurchaseRequestStatus;
use App\Events\DeliveryScheduleStatusChanged;
use App\Models\DeliverySchedule;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TransactionNotificationArchitectureTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        DB::table('notifications')->delete();
    }

    public function test_transaction_notification_is_sent_only_after_commit(): void
    {
        $kitchen = SppgKitchen::query()->where('code', 'SPPG-BDG-001')->firstOrFail();
        $requester = User::query()->where('email', 'role.requester@example.test')->firstOrFail();

        $request = PurchaseRequest::query()->create([
            'number' => 'PR-AFTER-COMMIT-001',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $requester->getKey(),
            'status' => PurchaseRequestStatus::Draft,
            'description' => 'Pengujian notifikasi setelah transaksi commit.',
        ]);

        DB::beginTransaction();

        $request->update(['status' => PurchaseRequestStatus::Submitted]);

        $this->assertSame(0, DB::table('notifications')->count());

        DB::rollBack();

        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(PurchaseRequestStatus::Draft, $request->refresh()->status);

        DB::transaction(function () use ($request): void {
            $request->update(['status' => PurchaseRequestStatus::Submitted]);

            $this->assertSame(0, DB::table('notifications')->count());
        });

        $notification = DB::table('notifications')
            ->where('data', 'like', '%Purchase Request menunggu approval%')
            ->first();

        $this->assertNotNull($notification);

        $data = json_decode((string) $notification->data, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pr', $data['business_stage']);
        $this->assertSame(1, $data['business_stage_order']);
        $this->assertSame('PR', $data['business_stage_label']);
        $this->assertSame($request->getKey(), $data['purchase_request_id']);
        $this->assertSame($request->number, $data['purchase_request_number']);
        $this->assertSame('pr', $data['current_stage']);
    }

    public function test_delivery_status_change_is_a_first_class_transaction_event(): void
    {
        Event::fake([DeliveryScheduleStatusChanged::class]);

        $order = PurchaseOrder::query()->firstOrFail();
        $actor = User::query()->firstOrFail();

        $schedule = DeliverySchedule::query()->create([
            'number' => 'DEL-NOTIFY-001',
            'purchase_order_id' => $order->getKey(),
            'planned_delivery_at' => now()->addHour(),
            'status' => DeliveryScheduleStatus::Planned,
            'created_by' => $actor->getKey(),
        ]);

        $schedule->update(['status' => DeliveryScheduleStatus::InTransit]);

        Event::assertDispatched(
            DeliveryScheduleStatusChanged::class,
            fn (DeliveryScheduleStatusChanged $event): bool => $event->deliveryScheduleId === $schedule->getKey()
                && $event->status === DeliveryScheduleStatus::InTransit->value,
        );
    }
}

