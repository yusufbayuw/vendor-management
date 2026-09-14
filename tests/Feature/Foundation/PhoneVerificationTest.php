<?php

namespace Tests\Feature\Foundation;

use App\Contracts\OtpChannel;
use App\Enums\SystemRole;
use App\Models\User;
use App\Services\Auth\LogOtpChannel;
use App\Services\Auth\PhoneVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_is_hashed_and_can_verify_phone(): void
    {
        config([
            'phone-verification.resend_cooldown_seconds' => 0,
            'phone-verification.expires_in_seconds' => 300,
        ]);

        $channel = new class implements OtpChannel
        {
            public ?string $lastCode = null;

            public function send(string $phone, string $code): void
            {
                $this->lastCode = $code;
            }
        };

        $this->app->instance(OtpChannel::class, $channel);

        $user = User::factory()->create([
            'email' => null,
            'phone' => '081234567890',
        ]);

        $verification = app(PhoneVerificationService::class)->send($user, '127.0.0.1');

        $this->assertNotNull($channel->lastCode);
        $this->assertNotSame($channel->lastCode, $verification->code_hash);
        $this->assertTrue(Hash::check($channel->lastCode, $verification->code_hash));
        $this->assertNull($user->fresh()->phone_verified_at);

        app(PhoneVerificationService::class)->verify($user, $channel->lastCode);

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertNotNull($verification->fresh()->verified_at);
    }

    public function test_changing_phone_resets_verification(): void
    {
        $user = User::factory()->create([
            'phone' => '081234567890',
        ]);

        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->assertTrue($user->fresh()->hasVerifiedPhone());

        $user->update(['phone' => '081298765432']);

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertFalse($user->fresh()->hasVerifiedPhone());
    }

    public function test_manual_mode_allows_unverified_supplier_to_use_portal(): void
    {
        config(['phone-verification.mode' => 'manual']);
        Role::findOrCreate(SystemRole::SupplierAdmin->value, 'web');

        $user = User::factory()->create([
            'email' => null,
            'username' => 'suppliermanual',
            'phone' => '081234567890',
            'phone_verified_at' => null,
        ]);
        $user->assignRole(SystemRole::SupplierAdmin->value);

        $this->actingAs($user)
            ->get('/supplier')
            ->assertOk();
    }

    public function test_otp_mode_redirects_unverified_supplier_to_phone_verification_page(): void
    {
        config(['phone-verification.mode' => 'otp']);
        Role::findOrCreate(SystemRole::SupplierAdmin->value, 'web');

        $user = User::factory()->create([
            'email' => null,
            'username' => 'supplierotp',
            'phone' => '081234567890',
            'phone_verified_at' => null,
        ]);
        $user->assignRole(SystemRole::SupplierAdmin->value);

        $this->actingAs($user)
            ->get('/supplier')
            ->assertRedirect('/supplier/verify-phone');

        $this->actingAs($user)
            ->get('/supplier/verify-phone')
            ->assertOk()
            ->assertSee('Verifikasi Nomor HP');
    }

    public function test_production_otp_mode_rejects_log_channel(): void
    {
        $originalEnvironment = $this->app['env'];

        try {
            config([
                'phone-verification.mode' => 'otp',
                'phone-verification.driver' => 'log',
            ]);
            $this->app['env'] = 'production';
            $this->app->forgetInstance(OtpChannel::class);

            $this->app->make(OtpChannel::class);
            $this->fail('Log OTP channel seharusnya ditolak pada production saat mode OTP aktif.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('OTP_CHANNEL=log tidak boleh digunakan', $exception->getMessage());
        } finally {
            $this->app['env'] = $originalEnvironment;
            $this->app->forgetInstance(OtpChannel::class);
        }
    }

    public function test_production_manual_mode_can_keep_log_channel_unresolved_from_users(): void
    {
        $originalEnvironment = $this->app['env'];

        try {
            config([
                'phone-verification.mode' => 'manual',
                'phone-verification.driver' => 'log',
            ]);
            $this->app['env'] = 'production';
            $this->app->forgetInstance(OtpChannel::class);

            $this->assertInstanceOf(LogOtpChannel::class, $this->app->make(OtpChannel::class));
        } finally {
            $this->app['env'] = $originalEnvironment;
            $this->app->forgetInstance(OtpChannel::class);
        }
    }
}
