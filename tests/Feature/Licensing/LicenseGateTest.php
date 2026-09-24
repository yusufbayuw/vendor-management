<?php

namespace Tests\Feature\Licensing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LicenseGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('app.license_key', null);
        Storage::fake('local');
        Cache::flush();
    }

    public function test_unlicensed_application_redirects_to_activation_page(): void
    {
        $this->get('/')
            ->assertRedirect(route('license.show'));
    }

    public function test_activation_page_remains_accessible_without_a_license(): void
    {
        $this->get('/license/activate')
            ->assertOk()
            ->assertSee('Aktivasi Lisensi')
            ->assertSee('System Signature');
    }

    public function test_invalid_license_key_is_rejected(): void
    {
        $this->post('/license/activate', [
            'license_key' => 'invalid-license',
        ])->assertSessionHas('error');
    }
}
