<?php

namespace App\Providers\Filament;

use App\Filament\Supplier\Pages\Auth\Register;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SupplierPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('supplier')
            ->path('supplier')
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->profile(isSimple: false)
            ->brandName('Portal Supplier SPPG')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->databaseNotifications()
            ->discoverResources(
                in: app_path('Filament/Supplier/Resources'),
                for: 'App\\Filament\\Supplier\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Supplier/Pages'),
                for: 'App\\Filament\\Supplier\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Supplier/Widgets'),
                for: 'App\\Filament\\Supplier\\Widgets',
            )
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
