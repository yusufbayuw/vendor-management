<?php

namespace App\Support\Theme;

use InvalidArgumentException;

final class ThemeRegistry
{
    public const DEFAULT_THEME = 'liquid-glass';

    public const DEFAULT_MODE = 'user';

    public const ASSET_VERSION = '2026.10.01.2';

    /**
     * @return array<string, ThemePack>
     */
    public function all(): array
    {
        return [
            'default' => new ThemePack(
                key: 'default',
                label: 'Filament Default',
                description: 'Tampilan native Filament tanpa skin tambahan. Selalu tersedia sebagai fallback aman.',
                previewTone: 'default',
            ),
            'liquid-glass' => new ThemePack(
                key: 'liquid-glass',
                label: 'Liquid Glass',
                description: 'Material transparan berlapis untuk navigasi dan kontrol, dengan content layer tetap solid dan mudah dibaca.',
                previewTone: 'liquid',
            ),
        ];
    }

    /**
     * @return array<string, ThemePack>
     */
    public function forPanel(string $panelId): array
    {
        return array_filter(
            $this->all(),
            static fn (ThemePack $theme): bool => $theme->supports($panelId),
        );
    }

    public function find(string $key, string $panelId): ?ThemePack
    {
        $theme = $this->all()[$key] ?? null;

        return $theme?->supports($panelId) ? $theme : null;
    }

    public function require(string $key, string $panelId): ThemePack
    {
        return $this->find($key, $panelId)
            ?? throw new InvalidArgumentException("Theme [{$key}] tidak tersedia untuk panel [{$panelId}].");
    }

    /**
     * @return array<int, string>
     */
    public function keysForPanel(string $panelId): array
    {
        return array_keys($this->forPanel($panelId));
    }

    /**
     * @return array<string, string>
     */
    public function modes(): array
    {
        return [
            'user' => 'Ikuti preferensi pengguna',
            'system' => 'Ikuti sistem/perangkat',
            'light' => 'Selalu terang',
            'dark' => 'Selalu gelap',
        ];
    }

    public function isValidMode(string $mode): bool
    {
        return array_key_exists($mode, $this->modes());
    }

    public function defaultForPanel(string $panelId): string
    {
        if ($this->find(self::DEFAULT_THEME, $panelId) !== null) {
            return self::DEFAULT_THEME;
        }

        return array_key_first($this->forPanel($panelId)) ?? 'default';
    }
}
