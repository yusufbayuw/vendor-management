<?php

namespace App\Support\Theme;

final readonly class ThemePack
{
    /**
     * @param  array<int, string>  $panels
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $previewTone,
        public array $panels = ['admin', 'supplier'],
    ) {}

    public function supports(string $panelId): bool
    {
        return in_array($panelId, $this->panels, true);
    }
}
