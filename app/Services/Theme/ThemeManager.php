<?php

namespace App\Services\Theme;

use App\Models\ThemeSetting;
use App\Support\Theme\ThemeRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class ThemeManager
{
    private const PANELS = ['admin', 'supplier'];

    public function __construct(private readonly ThemeRegistry $registry) {}

    /**
     * @return array{panel_id: string, theme: string, color_mode: string}
     */
    public function resolve(string $panelId): array
    {
        $this->assertPanel($panelId);

        $fallback = [
            'panel_id' => $panelId,
            'theme' => $this->registry->defaultForPanel($panelId),
            'color_mode' => ThemeRegistry::DEFAULT_MODE,
        ];

        if (! Schema::hasTable('theme_settings')) {
            return $fallback;
        }

        return Cache::rememberForever($this->cacheKey($panelId), function () use ($panelId, $fallback): array {
            $setting = ThemeSetting::query()
                ->where('panel_id', $panelId)
                ->first();

            if ($setting === null) {
                return $fallback;
            }

            $theme = $this->registry->find((string) $setting->theme, $panelId)?->key
                ?? $fallback['theme'];

            $mode = $this->registry->isValidMode((string) $setting->color_mode)
                ? (string) $setting->color_mode
                : $fallback['color_mode'];

            return [
                'panel_id' => $panelId,
                'theme' => $theme,
                'color_mode' => $mode,
            ];
        });
    }

    public function update(
        string $panelId,
        string $theme,
        string $colorMode,
        ?int $updatedBy = null,
    ): ThemeSetting {
        $this->assertPanel($panelId);
        $this->registry->require($theme, $panelId);

        if (! $this->registry->isValidMode($colorMode)) {
            throw new InvalidArgumentException("Mode tampilan [{$colorMode}] tidak valid.");
        }

        if (! Schema::hasTable('theme_settings')) {
            throw new RuntimeException('Tabel theme_settings belum tersedia. Jalankan migrasi terlebih dahulu.');
        }

        $setting = ThemeSetting::query()->updateOrCreate(
            ['panel_id' => $panelId],
            [
                'theme' => $theme,
                'color_mode' => $colorMode,
                'updated_by' => $updatedBy,
            ],
        );

        $this->forget($panelId);

        return $setting->refresh();
    }

    public function resetToRecommended(string $panelId, ?int $updatedBy = null): ThemeSetting
    {
        return $this->update(
            $panelId,
            $this->registry->defaultForPanel($panelId),
            ThemeRegistry::DEFAULT_MODE,
            $updatedBy,
        );
    }

    public function forget(string $panelId): void
    {
        Cache::forget($this->cacheKey($panelId));
    }

    private function cacheKey(string $panelId): string
    {
        return "appearance.theme.{$panelId}";
    }

    private function assertPanel(string $panelId): void
    {
        if (! in_array($panelId, self::PANELS, true)) {
            throw new InvalidArgumentException("Panel [{$panelId}] tidak didukung oleh theme manager.");
        }
    }
}
