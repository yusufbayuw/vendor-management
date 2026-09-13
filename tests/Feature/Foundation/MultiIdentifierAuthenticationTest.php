<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MultiIdentifierAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_only_user_can_authenticate_by_phone_and_username(): void
    {
        $user = User::factory()->create([
            'username' => 'Vendor.Bandung',
            'email' => null,
            'phone' => '0812-3456-7890',
            'password' => 'secret-password',
        ]);

        $this->assertSame('vendor.bandung', $user->username);
        $this->assertSame('6281234567890', $user->phone);

        $this->assertTrue(Auth::attempt(LoginIdentifier::credentials('+62 812 3456 7890', 'secret-password')));
        Auth::logout();

        $this->assertTrue(Auth::attempt(LoginIdentifier::credentials('Vendor.Bandung', 'secret-password')));
    }

    public function test_user_can_still_authenticate_by_email(): void
    {
        User::factory()->create([
            'username' => 'finance.user',
            'email' => 'Finance@Example.COM',
            'password' => 'secret-password',
        ]);

        $this->assertTrue(Auth::attempt(LoginIdentifier::credentials('finance@example.com', 'secret-password')));
    }
}
