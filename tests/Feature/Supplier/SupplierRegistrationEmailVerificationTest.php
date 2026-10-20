<?php

namespace Tests\Feature\Supplier;

use App\Enums\SystemRole;
use App\Filament\Supplier\Pages\Auth\Register;
use App\Models\User;
use App\Notifications\VerifySupplierEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
