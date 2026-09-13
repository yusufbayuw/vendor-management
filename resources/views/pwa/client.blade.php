@if ($showBanner)
    <aside id="pwa-onboarding" hidden aria-live="polite">
        <button type="button" class="pwa-onboarding__close" data-pwa-dismiss aria-label="Tutup">&times;</button>

        <div class="pwa-onboarding__content">
            <strong>Gunakan aplikasi SPPG di perangkat ini</strong>
            <span data-pwa-status>Memeriksa dukungan perangkat…</span>
        </div>

        <div class="pwa-onboarding__actions">
            <button type="button" data-pwa-install-button hidden>Install aplikasi</button>
            <button type="button" data-pwa-enable-button hidden>Aktifkan notifikasi</button>
            <button type="button" data-pwa-disable-button hidden>Nonaktifkan notifikasi</button>
        </div>
    </aside>
@endif

@php
    $pushRoutesAvailable = \Illuminate\Support\Facades\Route::has('push.vapid-public-key')
        && \Illuminate\Support\Facades\Route::has('push.subscriptions.store')
        && \Illuminate\Support\Facades\Route::has('push.subscriptions.destroy');
    $pushPackageAvailable = class_exists(\NotificationChannels\WebPush\WebPushChannel::class);
    $pushConfigured = $pushRoutesAvailable
        && $pushPackageAvailable
        && filled(config('webpush.vapid.public_key'))
        && filled(config('webpush.vapid.private_key'));

    $pwaConfig = [
        'authenticated' => auth()->check(),
        'pushConfigured' => $pushConfigured,
        'vapidUrl' => $pushRoutesAvailable ? route('push.vapid-public-key') : null,
        'subscribeUrl' => $pushRoutesAvailable ? route('push.subscriptions.store') : null,
        'unsubscribeUrl' => $pushRoutesAvailable ? route('push.subscriptions.destroy') : null,
        'serviceWorkerUrl' => asset('sw.js'),
    ];
@endphp

<script id="vendor-management-pwa-config" type="application/json">
    @json($pwaConfig)
</script>
<script src="{{ asset('js/pwa.js') }}" defer></script>

<style>
    #pwa-onboarding {
        position: fixed;
        z-index: 100;
        right: 1rem;
        bottom: 1rem;
        width: min(27rem, calc(100vw - 2rem));
        padding: 1rem;
        border: 1px solid rgb(253 230 138);
        border-radius: .9rem;
        background: rgb(255 255 255 / .98);
        box-shadow: 0 18px 45px rgb(15 23 42 / .2);
        color: rgb(15 23 42);
        backdrop-filter: blur(12px);
    }

    .dark #pwa-onboarding {
        border-color: rgb(120 53 15);
        background: rgb(17 24 39 / .97);
        color: rgb(248 250 252);
    }

    .pwa-onboarding__close {
        position: absolute;
        top: .35rem;
        right: .55rem;
        padding: .2rem .4rem;
        color: rgb(100 116 139);
        font-size: 1.4rem;
        line-height: 1;
    }

    .pwa-onboarding__content {
        display: grid;
        gap: .25rem;
        padding-right: 1.5rem;
    }

    .pwa-onboarding__content span {
        color: rgb(71 85 105);
        font-size: .875rem;
    }

    .dark .pwa-onboarding__content span {
        color: rgb(203 213 225);
    }

    .pwa-onboarding__actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-top: .85rem;
    }

    .pwa-onboarding__actions button {
        padding: .55rem .8rem;
        border-radius: .55rem;
        background: rgb(245 158 11);
        color: rgb(69 26 3);
        font-size: .8rem;
        font-weight: 700;
    }

    .pwa-onboarding__actions button[data-pwa-disable-button] {
        border: 1px solid rgb(203 213 225);
        background: white;
        color: rgb(51 65 85);
    }

    .dark .pwa-onboarding__actions button[data-pwa-disable-button] {
        border-color: rgb(71 85 105);
        background: rgb(30 41 59);
        color: rgb(226 232 240);
    }
</style>
