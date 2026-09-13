<div
    x-data="{ isDark: document.documentElement.classList.contains('dark') }"
    x-init="
        const el = document.documentElement;
        const observer = new MutationObserver(() => { isDark = el.classList.contains('dark') });
        observer.observe(el, { attributes: true, attributeFilter: ['class'] });
    "
    class="space-y-2"
>
    <div class="flex items-center justify-between gap-3">
        <span class="text-sm font-medium text-gray-950 dark:text-white">Kode Keamanan</span>

        <x-filament::button
            type="button"
            color="gray"
            size="sm"
            icon="heroicon-m-arrow-path"
            wire:click="refreshCaptcha"
        >
            Captcha baru
        </x-filament::button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <img
            :src="isDark ? @js($darkCaptchaImage) : @js($lightCaptchaImage)"
            alt="Kode keamanan captcha"
            class="block h-16 w-full object-cover"
            draggable="false"
        >
    </div>
</div>
