<?php

namespace Tests\Feature\Foundation;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
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

        $this->assertTrue(
            DB::table('notifications')
                ->where('data', 'like', '%Purchase Request menunggu approval%')
                ->exists(),
        );
    }
}
