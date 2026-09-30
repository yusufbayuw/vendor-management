<?php

namespace Tests\Feature\Foundation;

use App\Models\AuditLog;
use App\Models\ThemeSetting;
use App\Services\Theme\ThemeManager;
use App\Support\Theme\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ThemeManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_manager_uses_liquid_glass_as_safe_recommended_default(): void
    {
        $appearance = app(ThemeManager::class)->resolve('admin');

        $this->assertSame('admin', $appearance['panel_id']);
        $this->assertSame(ThemeRegistry::DEFAULT_THEME, $appearance['theme']);
        $this->assertSame(ThemeRegistry::DEFAULT_MODE, $appearance['color_mode']);
    }

    public function test_theme_can_be_changed_per_panel_without_rebuilding_assets(): void
    {
        $manager = app(ThemeManager::class);

        $manager->update('admin', 'default', 'dark');

        $this->assertDatabaseHas('theme_settings', [
            'panel_id' => 'admin',
            'theme' => 'default',
            'color_mode' => 'dark',
        ]);

        $this->assertSame([
            'panel_id' => 'admin',
            'theme' => 'default',
            'color_mode' => 'dark',
        ], $manager->resolve('admin'));

        $this->assertSame(ThemeRegistry::DEFAULT_THEME, $manager->resolve('supplier')['theme']);
    }

    public function test_theme_changes_are_audited(): void
    {
        app(ThemeManager::class)->update('admin', 'default', 'light');

        $setting = ThemeSetting::query()->where('panel_id', 'admin')->firstOrFail();

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $setting->getMorphClass())
            ->where('auditable_id', $setting->getKey())
            ->where('event', 'created')
            ->exists());
    }

    public function test_unknown_theme_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ThemeManager::class)->update('admin', 'theme-from-request', 'user');
    }

    public function test_unknown_panel_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ThemeManager::class)->resolve('unknown-panel');
    }
}
