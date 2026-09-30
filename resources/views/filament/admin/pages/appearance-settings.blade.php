<x-filament-panels::page>
    @php
        $panels = [
            'admin' => [
                'title' => 'Panel Admin',
                'description' => 'Back-office procurement. Liquid Glass menggunakan density yang lebih rapat agar tabel dan transaksi tetap cepat dipindai.',
                'themeModel' => 'adminTheme',
                'modeModel' => 'adminMode',
            ],
            'supplier' => [
                'title' => 'Panel Supplier',
                'description' => 'Portal supplier. Liquid Glass menggunakan kontrol yang lebih lapang dan touch target yang lebih nyaman.',
                'themeModel' => 'supplierTheme',
                'modeModel' => 'supplierMode',
            ],
        ];

        $canManageAppearance = $this->canManage();
    @endphp

    <div class="theme-settings-shell">
        <div class="theme-settings-callout">
            <div class="theme-settings-callout-icon" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-sparkles" />
            </div>
            <div>
                <h2>Theme Pack System</h2>
                <p>
                    Theme adalah paket visual yang sudah dibundel dan diizinkan aplikasi.
                    Pergantian theme hanya mengubah setting database—tidak mengunggah CSS,
                    tidak menjalankan build, dan tidak mengeksekusi command server.
                </p>
            </div>
        </div>

        @if (! $canManageAppearance)
            <div class="theme-settings-readonly" role="status">
                Anda memiliki akses lihat. Perubahan theme memerlukan permission <code>appearance.manage</code>.
            </div>
        @endif

        @foreach ($panels as $panelId => $panel)
            @php
                $themeModel = $panel['themeModel'];
                $modeModel = $panel['modeModel'];
                $activeTheme = $this->{$themeModel};
            @endphp

            <section class="theme-settings-panel">
                <header class="theme-settings-panel-header">
                    <div>
                        <div class="theme-settings-panel-kicker">{{ $panelId === 'admin' ? '/admin' : '/supplier' }}</div>
                        <h2>{{ $panel['title'] }}</h2>
                        <p>{{ $panel['description'] }}</p>
                    </div>

                    @if ($canManageAppearance)
                        <x-filament::button
                            color="gray"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            wire:click="resetPanel('{{ $panelId }}')"
                            wire:loading.attr="disabled"
                            wire:target="resetPanel('{{ $panelId }}')"
                        >
                            Rekomendasi
                        </x-filament::button>
                    @endif
                </header>

                <div class="theme-pack-grid">
                    @foreach ($this->themesForPanel($panelId) as $theme)
                        @php
                            $selected = $activeTheme === $theme->key;
                        @endphp

                        <label
                            class="theme-pack-card {{ $selected ? 'is-selected' : '' }}"
                            data-theme-key="{{ $theme->key }}"
                        >
                            <input
                                class="theme-pack-radio"
                                type="radio"
                                name="{{ $themeModel }}"
                                value="{{ $theme->key }}"
                                wire:model.live="{{ $themeModel }}"
                                @disabled(! $canManageAppearance)
                            >

                            <div
                                class="theme-pack-preview"
                                data-preview-theme="{{ $theme->previewTone }}"
                                data-preview-panel="{{ $panelId }}"
                                aria-hidden="true"
                            >
                                <div class="theme-preview-sidebar">
                                    <span class="theme-preview-brand"></span>
                                    <span class="theme-preview-nav is-active"></span>
                                    <span class="theme-preview-nav"></span>
                                    <span class="theme-preview-nav"></span>
                                </div>
                                <div class="theme-preview-content">
                                    <div class="theme-preview-topbar"></div>
                                    <div class="theme-preview-stats">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    <div class="theme-preview-table">
                                        <i></i><i></i><i></i><i></i>
                                    </div>
                                </div>
                            </div>

                            <div class="theme-pack-meta">
                                <div class="theme-pack-title-row">
                                    <strong>{{ $theme->label }}</strong>

                                    @if ($this->isRecommendedTheme($theme->key))
                                        <span class="theme-pack-recommended">Rekomendasi</span>
                                    @endif

                                    <span class="theme-pack-selected-mark" aria-label="Tema dipilih">
                                        <x-filament::icon icon="heroicon-m-check" />
                                    </span>
                                </div>
                                <p>{{ $theme->description }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="theme-mode-row">
                    <div>
                        <strong>Mode warna</strong>
                        <p>Atur kebijakan light/dark untuk panel ini tanpa mengubah paket theme.</p>
                    </div>

                    <label class="theme-mode-control">
                        <span class="sr-only">Mode warna {{ $panel['title'] }}</span>
                        <select wire:model.live="{{ $modeModel }}" @disabled(! $canManageAppearance)>
                            @foreach ($this->colorModes() as $mode => $label)
                                <option value="{{ $mode }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>
        @endforeach

        <div class="theme-settings-footer">
            <div>
                <strong>Perubahan aman untuk data operasional</strong>
                <p>Theme hanya mengubah presentation layer. PR, PO, delivery, invoice, payment, permission, dan data supplier tidak diubah.</p>
            </div>

            @if ($canManageAppearance)
                <x-filament::button
                    icon="heroicon-o-check-circle"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    Simpan & Aktifkan
                </x-filament::button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
