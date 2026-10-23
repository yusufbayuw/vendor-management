<?php

namespace Tests\Feature\Supplier;

use App\Enums\SystemRole;
use App\Filament\Supplier\Pages\Auth\Register;
use App\Models\Supplier;
use App\Services\Auth\PhoneVerificationService;
use App\Models\User;
use App\Notifications\VerifySupplierEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierRegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['phone-verification.mode' => 'manual']);

        Role::findOrCreate(SystemRole::SupplierAdmin->value, 'web');
        Filament::setCurrentPanel(Filament::getPanel('supplier'));
        Notification::fake();
    }

    public function test_supplier_registration_with_email_uses_the_existing_verification_notification(): void
    {
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('supplier.with-email@example.test', '081200000091'))
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'supplier.with-email@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole(SystemRole::SupplierAdmin->value));
        $this->assertSame(1, $user->suppliers()->count());
        $this->assertTrue($user->suppliers()->firstOrFail()->pivot->is_owner);
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentToTimes($user, VerifySupplierEmail::class, 1);
        $this->assertTrue(app('router')->has('verification.verify'));
        $this->assertFalse(app('router')->has('filament.supplier.auth.email-verification.verify'));

        $verificationUrl = Notification::sent($user, VerifySupplierEmail::class)
            ->sole()
            ->toMail($user)
            ->actionUrl;

        $this->assertStringContainsString('/email/verify/', $verificationUrl);

        auth()->logout();

        $this->get($verificationUrl)
            ->assertRedirect('/supplier/login?email_verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_supplier_registration_without_email_does_not_attempt_email_verification(): void
    {
        Livewire::test(Register::class)
            ->set('data', $this->registrationData(null, '081200000092'))
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('phone', '6281200000092')->firstOrFail();

        $this->assertNull($user->email);
        $this->assertTrue($user->hasRole(SystemRole::SupplierAdmin->value));
        $this->assertSame(1, $user->suppliers()->count());

        Notification::assertNotSentTo($user, VerifySupplierEmail::class);
    }


    public function test_retry_with_existing_email_or_phone_cannot_duplicate_supplier_ownership(): void
    {
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('supplier.retry@example.test', '081200000093'))
            ->call('register')
            ->assertHasNoFormErrors();

        auth()->logout();

        // Different phone, but the email becomes identical when stored by User.
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('SUPPLIER.RETRY@EXAMPLE.TEST', '081200000094'))
            ->call('register')
            ->assertHasFormErrors(['email']);

        // Same phone in international format, but a different email.
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('other.retry@example.test', '6281200000093'))
            ->call('register')
            ->assertHasFormErrors(['supplier_phone']);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame(1, DB::table('supplier_users')->count());
        $this->assertTrue(User::query()->sole()->suppliers()->sole()->pivot->is_owner);
    }

    public function test_failed_phone_otp_dispatch_does_not_create_an_orphan_or_duplicate_on_retry(): void
    {
        config(['phone-verification.mode' => 'otp']);

        $otp = Mockery::mock(PhoneVerificationService::class);
        $otp->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('OTP provider temporarily unavailable'));

        app()->instance(PhoneVerificationService::class, $otp);

        Livewire::test(Register::class)
            ->set('data', $this->registrationData(null, '081200000095'))
            ->call('register')
            ->assertHasNoFormErrors();

        auth()->logout();

        Livewire::test(Register::class)
            ->set('data', $this->registrationData(null, '6281200000095'))
            ->call('register')
            ->assertHasFormErrors(['supplier_phone']);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame(1, DB::table('supplier_users')->count());
        $this->assertNull(User::query()->sole()->phone_verified_at);
    }

    /** @return array<string, mixed> */
    private function registrationData(?string $email, string $phone): array
    {
        return [
            'name' => 'Pemilik Usaha',
            'supplier_phone' => $phone,
            'email' => $email,
            'legal_name' => 'Toko Pangan Test',
            'display_name' => 'Toko Pangan Test',
            'supplier_type' => 'individual',
            'password' => 'StrongPassword123!',
            'passwordConfirmation' => 'StrongPassword123!',
        ];
    }
}
