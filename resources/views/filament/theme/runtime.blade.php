@php
    $appearance = app(\App\Services\Theme\ThemeManager::class)->resolve($panelId);
    $themeAsset = asset('css/filament/theme-packs.css')
        . '?v='
        . \App\Support\Theme\ThemeRegistry::ASSET_VERSION;
@endphp

<script>
    (() => {
        const root = document.documentElement;
        const panel = @js($appearance['panel_id']);
        const theme = @js($appearance['theme']);
        const mode = @js($appearance['color_mode']);

        root.dataset.appPanel = panel;
        root.dataset.appTheme = theme;
        root.dataset.appMode = mode;

        if (mode === 'user') {
            return;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');

        const applyMode = () => {
            const dark = mode === 'dark' || (mode === 'system' && media.matches);

            root.classList.toggle('dark', dark);
            root.style.colorScheme = dark ? 'dark' : 'light';
        };

        applyMode();

        if (mode === 'system') {
            media.addEventListener?.('change', applyMode);
        }
    })();
</script>

<link rel="stylesheet" href="{{ $themeAsset }}">
