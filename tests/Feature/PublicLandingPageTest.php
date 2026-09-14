<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLandingPageTest extends TestCase
{
    public function test_public_landing_page_presents_supplier_onboarding_and_portal_links(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Portal Kemitraan Supplier SPPG')
            ->assertSee('Mulai Daftar Supplier')
            ->assertSee('Alur kemitraan')
            ->assertSee('Purchase Order yang jelas')
            ->assertSee('Keamanan & transparansi')
            ->assertSee(url('/supplier/register'), false)
            ->assertSee(url('/supplier/login'), false)
            ->assertSee(url('/admin/login'), false);
    }

    public function test_supplier_authentication_entry_points_remain_available(): void
    {
        $this->get('/supplier/login')->assertOk();
        $this->get('/supplier/register')->assertOk();
    }
}
