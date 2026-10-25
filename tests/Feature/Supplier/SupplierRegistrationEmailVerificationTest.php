<?php

namespace Tests\Feature\Supplier;

use App\Enums\SystemRole;
use App\Filament\Supplier\Pages\Auth\Register;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\VerifySupplierEmail;
use App\Services\Auth\PhoneVerificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
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
        $this->assertSame(1, (int) $user->suppliers()->firstOrFail()->pivot->is_owner);
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

    public function test_retry_with_existing_email_cannot_duplicate_supplier_ownership(): void
    {
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('supplier.retry@example.test', '081200000093'))
            ->call('register')
            ->assertHasNoFormErrors();

        auth()->logout();

        // User::email lowercases the value; upper case cannot bypass uniqueness.
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('SUPPLIER.RETRY@EXAMPLE.TEST', '081200000094'))
            ->call('register')
            ->assertHasFormErrors(['email']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('supplier_users', 1);
        $this->assertSame(1, (int) User::query()->sole()->suppliers()->sole()->pivot->is_owner);
    }

    public function test_retry_with_equivalent_international_phone_cannot_duplicate_supplier(): void
    {
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('supplier.phone@example.test', '081200000093'))
            ->call('register')
            ->assertHasNoFormErrors();

        auth()->logout();

        $this->assertSame('6281200000093', User::query()->sole()->phone);

        // This format must normalize to the same phone as 081200000093.
        Livewire::test(Register::class)
            ->set('data', $this->registrationData('other.phone@example.test', '6281200000093'))
            ->call('register')
            ->assertHasFormErrors(['supplier_phone']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('supplier_users', 1);
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

    public function test_failure_after_user_and_supplier_creation_rolls_back_all_records_before_retry(): void
    {
        $failOnce = true;

        DB::listen(static function ($query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower(ltrim($query->sql)), 'insert')
                && str_contains($query->sql, 'supplier_users')) {
                $failOnce = false;

                throw new RuntimeException('Simulated pivot write failure');
            }
        });

        try {
            Livewire::test(Register::class)
                ->set('data', $this->registrationData(null, '081200000096'))
                ->call('register');

            $this->fail('The simulated transaction failure must be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated pivot write failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('supplier_users', 0);

        // Retrying the exact same registration must create exactly one owner.
        Livewire::test(Register::class)
            ->set('data', $this->registrationData(null, '081200000096'))
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('supplier_users', 1);
        $this->assertSame(1, (int) User::query()->sole()->suppliers()->sole()->pivot->is_owner);
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
