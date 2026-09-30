<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use App\Support\Auth\LoginCaptcha;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_login_urls_redirect_to_unified_login(): void
    {
        $this->get('/admin/login')->assertRedirect('/login');
        $this->get('/supplier/login')->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Email / Nomor HP / Username')
            ->assertSee('Kode Keamanan')
            ->assertSee('action="/login"', false);
    }

    public function test_filament_panels_keep_login_actions_for_legacy_routes(): void
    {
        $this->assertNotNull(Filament::getPanel('admin')->getLoginRouteAction());
        $this->assertNotNull(Filament::getPanel('supplier')->getLoginRouteAction());
    }

    public function test_internal_user_logs_in_once_and_is_sent_to_admin_panel(): void
    {
        Role::findOrCreate(SystemRole::Procurement->value, 'web');

        $user = User::factory()->create([
            'username' => 'procurement.user',
            'email' => 'procurement@example.test',
            'password' => 'password',
        ]);
        $user->assignRole(SystemRole::Procurement->value);

        $response = $this
            ->withSession($this->captchaSession('ABCDE'))
            ->post('/login', [
                'login' => 'procurement.user',
                'password' => 'password',
                'captcha' => 'ABCDE',
            ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_supplier_can_login_with_phone_and_is_sent_to_supplier_panel(): void
    {
        Role::findOrCreate(SystemRole::SupplierAdmin->value, 'web');

        $user = User::factory()->create([
            'email' => null,
            'username' => 'supplier.user',
            'phone' => '081234567890',
            'password' => 'password',
        ]);
        $user->assignRole(SystemRole::SupplierAdmin->value);

        $response = $this
            ->withSession($this->captchaSession('FGHJK'))
            ->post('/login', [
                'login' => '081234567890',
                'password' => 'password',
                'captcha' => 'FGHJK',
            ]);

        $response->assertRedirect('/supplier');
        $this->assertAuthenticatedAs($user);
    }

    public function test_intended_url_cannot_cross_panel_boundary(): void
    {
        Role::findOrCreate(SystemRole::SupplierAdmin->value, 'web');

        $user = User::factory()->create([
            'username' => 'supplier.cross-panel',
            'password' => 'password',
        ]);
        $user->assignRole(SystemRole::SupplierAdmin->value);

        $response = $this
            ->withSession(array_merge(
                $this->captchaSession('LMNPQ'),
                ['url.intended' => 'https://vendor.test/admin'],
            ))
            ->post('/login', [
                'login' => 'supplier.cross-panel',
                'password' => 'password',
                'captcha' => 'LMNPQ',
            ]);

        $response->assertRedirect('/supplier');
    }

    /** @return array<string, mixed> */
    private function captchaSession(string $answer): array
    {
        return [
            'auth.login_captcha' => [
                'hash' => hash_hmac(
                    'sha256',
                    strtoupper($answer),
                    (string) config('app.key', 'vendor-management-login-captcha'),
                ),
                'image_light' => 'data:image/png;base64,test',
                'image_dark' => 'data:image/png;base64,test',
                'expires_at' => now()->addMinutes(5)->timestamp,
            ],
        ];
    }
}
