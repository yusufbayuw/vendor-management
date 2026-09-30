<?php

namespace App\Filament\Admin\Pages;

use App\Enums\SystemPermission;
use App\Enums\SystemRole;
use App\Services\Theme\ThemeManager;
use App\Support\Theme\ThemePack;
use App\Support\Theme\ThemeRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UnitEnum;

class AppearanceSettings extends Page
{
    protected static ?string $navigationLabel = 'Tampilan & Tema';

    protected static ?string $title = 'Tampilan & Tema';

    protected static string|UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $slug = 'settings/appearance';

    protected string $view = 'filament.admin.pages.appearance-settings';

    public string $adminTheme = ThemeRegistry::DEFAULT_THEME;

    public string $adminMode = ThemeRegistry::DEFAULT_MODE;

    public string $supplierTheme = ThemeRegistry::DEFAULT_THEME;

    public string $supplierMode = ThemeRegistry::DEFAULT_MODE;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->loadSettings();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && (
                $user->hasRole(SystemRole::SuperAdmin->value)
                || $user->can(SystemPermission::AppearanceView->value)
            );
    }

    public function canManage(): bool
    {
        $user = auth()->user();

        return $user !== null
            && (
                $user->hasRole(SystemRole::SuperAdmin->value)
                || $user->can(SystemPermission::AppearanceManage->value)
            );
    }

    public function getSubheading(): ?string
    {
        return 'Pilih paket tema untuk setiap panel. Semua aset sudah dibundel di aplikasi, sehingga aktivasi tidak menjalankan build, command shell, atau perubahan file.';
    }

    /**
     * @return array<string, ThemePack>
     */
    public function themesForPanel(string $panelId): array
    {
        return app(ThemeRegistry::class)->forPanel($panelId);
    }

    /**
     * @return array<string, string>
     */
    public function colorModes(): array
    {
        return app(ThemeRegistry::class)->modes();
    }

    public function isRecommendedTheme(string $theme): bool
    {
        return $theme === ThemeRegistry::DEFAULT_THEME;
    }

    public function save(): RedirectResponse
    {
        abort_unless($this->canManage(), 403);

        $registry = app(ThemeRegistry::class);

        $data = $this->validate([
            'adminTheme' => ['required', Rule::in($registry->keysForPanel('admin'))],
            'adminMode' => ['required', Rule::in(array_keys($registry->modes()))],
            'supplierTheme' => ['required', Rule::in($registry->keysForPanel('supplier'))],
            'supplierMode' => ['required', Rule::in(array_keys($registry->modes()))],
        ]);

        $manager = app(ThemeManager::class);
        $actorId = auth()->id();

        DB::transaction(function () use ($manager, $data, $actorId): void {
            $manager->update('admin', $data['adminTheme'], $data['adminMode'], $actorId);
            $manager->update('supplier', $data['supplierTheme'], $data['supplierMode'], $actorId);
        });

        Notification::make()
            ->title('Tampilan berhasil diperbarui')
            ->body('Tema aktif diterapkan pada panel Admin dan Supplier.')
            ->success()
            ->send();

        return redirect()->to(static::getUrl());
    }

    public function resetPanel(string $panelId): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        abort_unless(in_array($panelId, ['admin', 'supplier'], true), 404);

        app(ThemeManager::class)->resetToRecommended($panelId, auth()->id());

        Notification::make()
            ->title('Tema dikembalikan ke rekomendasi')
            ->body($panelId === 'admin' ? 'Panel Admin diperbarui.' : 'Panel Supplier diperbarui.')
            ->success()
            ->send();

        return redirect()->to(static::getUrl());
    }

    private function loadSettings(): void
    {
        $manager = app(ThemeManager::class);
        $admin = $manager->resolve('admin');
        $supplier = $manager->resolve('supplier');

        $this->adminTheme = $admin['theme'];
        $this->adminMode = $admin['color_mode'];
        $this->supplierTheme = $supplier['theme'];
        $this->supplierMode = $supplier['color_mode'];
    }
}
