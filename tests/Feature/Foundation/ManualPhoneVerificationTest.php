<?php

namespace Tests\Feature\Foundation;

use App\Models\PhoneVerificationRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\VerifySupplierEmail;
use App\Services\Auth\ManualPhoneVerificationService;
use App\Services\Supplier\SupplierOperationalEligibilityService;
use App\Services\Supplier\SupplierPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ManualPhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_whatsapp_request_lets_admin_contact_supplier_and_then_verify(): void
    {
        config(['phone-verification.mode' => 'manual']);

        $user = User::factory()->create([
            'name' => 'Budi Supplier',
            'username' => 'budi.supplier',
            'email' => null,
            'phone' => '081234567890',
            'phone_verified_at' => null,
        ]);
        $actor = User::factory()->create();

        $service = app(ManualPhoneVerificationService::class);
        $request = $service->currentOrCreate($user);
        $url = urldecode($service->whatsappUrlForAdmin($request));

        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $url);
        $this->assertStringContainsString('Halo Bapak/Ibu Budi Supplier', $url);
        $this->assertStringContainsString('Nomor terdaftar: +6281234567890', $url);
        $this->assertStringContainsString($request->reference, $url);
        $this->assertSame(PhoneVerificationRequest::STATUS_PENDING, $request->status);

        $service->verify($request, $actor);

        $user->refresh();
        $this->assertTrue($user->hasVerifiedPhone());
        $this->assertSame(PhoneVerificationRequest::METHOD_WHATSAPP_MANUAL, $user->phone_verification_method);
        $this->assertSame($actor->getKey(), $user->phone_verified_by);
        $this->assertSame(PhoneVerificationRequest::STATUS_VERIFIED, $request->fresh()->status);
    }

    public function test_manual_whatsapp_verified_account_can_access_operational_supplier(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'phone' => '081234567890',
            'phone_verified_at' => null,
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-MANUAL-001',
            'legal_name' => 'Supplier Verifikasi Manual',
        ]);
        $supplier->users()->attach($user->getKey(), ['is_owner' => true, 'is_active' => true]);

        $request = app(ManualPhoneVerificationService::class)->currentOrCreate($user);
        app(ManualPhoneVerificationService::class)->verify($request, User::factory()->create());

        $eligibility = \Mockery::mock(SupplierOperationalEligibilityService::class);
        $eligibility->shouldReceive('isOperationallyEligible')->andReturn(true);
        $this->app->instance(SupplierOperationalEligibilityService::class, $eligibility);

        $service = app(SupplierPortalAccessService::class);
        $this->assertTrue($service->hasActiveSupplier($user->fresh()));
        $this->assertSame([$supplier->getKey()], $service->activeSupplierIds($user->fresh()));
    }

    public function test_changing_phone_clears_manual_verification_metadata(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create([
            'phone' => '081234567890',
            'phone_verified_at' => now(),
            'phone_verification_method' => PhoneVerificationRequest::METHOD_WHATSAPP_MANUAL,
            'phone_verified_by' => $actor->getKey(),
        ]);

        $user->update(['phone' => '081298765432']);
        $user->refresh();

        $this->assertNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_method);
        $this->assertNull($user->phone_verified_by);
    }

    public function test_supplier_email_verification_notification_is_sent_directly(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'supplier@example.com',
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifySupplierEmail::class);
    }
}
