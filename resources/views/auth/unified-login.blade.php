<x-filament-panels::layout.simple>
    @include('filament.auth.login-background')

    <div class="fi-simple-page">
        <section class="grid auto-cols-fr gap-y-6">
            <x-filament-panels::header.simple
                heading="Masuk ke SPPG Vendor Management"
                :logo="true"
                :subheading="null"
            />

            <form method="POST" action="{{ route('login.store', absolute: false) }}" class="grid gap-y-6">
                @csrf

                <x-filament-forms::field-wrapper
                    id="login"
                    label="Email / Nomor HP / Username"
                    state-path="login"
                    required
                >
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="login"
                            type="text"
                            name="login"
                            :value="old('login')"
                            autocomplete="username"
                            autofocus
                            required
                        />
                    </x-filament::input.wrapper>
                </x-filament-forms::field-wrapper>

                <x-filament-forms::field-wrapper
                    id="password"
                    label="Kata sandi"
                    state-path="password"
                    required
                >
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        />
                    </x-filament::input.wrapper>
                </x-filament-forms::field-wrapper>

                <label class="flex items-center gap-x-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <x-filament::input.checkbox
                        name="remember"
                        value="1"
                        :checked="old('remember') === '1'"
                    />
                    <span>Ingat saya</span>
                </label>

                <x-filament-forms::field-wrapper
                    id="captcha"
                    label="Kode Keamanan"
                    state-path="captcha"
                    helper-text="Masukkan 5 karakter pada gambar. Huruf besar/kecil tidak dibedakan."
                    required
                >
                    <div class="flex items-center gap-3">
                        <div class="overflow-hidden rounded-lg bg-gray-950">
                            <img
                                id="unified-login-captcha-image"
                                src="{{ $captcha['image_light'] }}"
                                alt="Kode keamanan"
                                class="block h-auto max-w-full"
                            >
                        </div>

                        <x-filament::icon-button
                            id="refresh-unified-login-captcha"
                            type="button"
                            icon="heroicon-m-arrow-path"
                            color="gray"
                            tooltip="Muat kode baru"
                        />
                    </div>

                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="captcha"
                            type="text"
                            name="captcha"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            maxlength="5"
                            required
                        />
                    </x-filament::input.wrapper>
                </x-filament-forms::field-wrapper>

                <x-filament::button type="submit" class="w-full">
                    Masuk
                </x-filament::button>
            </form>

            <div class="grid gap-2 text-center text-sm text-gray-500 dark:text-gray-400">
                <a
                    href="{{ url('/supplier/password-reset/request') }}"
                    class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                >
                    Lupa kata sandi?
                </a>

                <p>
                    Belum menjadi supplier?
                    <a
                        href="{{ url('/supplier/register') }}"
                        class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                    >
                        Daftar supplier
                    </a>
                </p>
            </div>
        </section>
    </div>

    <script>
        (() => {
            const button = document.getElementById('refresh-unified-login-captcha');
            const image = document.getElementById('unified-login-captcha-image');
            const input = document.getElementById('captcha');

            if (! button || ! image || ! input) {
                return;
            }

            button.addEventListener('click', async () => {
                button.disabled = true;

                try {
                    const response = await fetch(@js(route('login.captcha', absolute: false)), {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });

                    if (! response.ok) {
                        throw new Error('Captcha refresh failed.');
                    }

                    const payload = await response.json();

                    image.src = payload.light;
                    input.value = '';
                    input.focus();
                } finally {
                    button.disabled = false;
                }
            });
        })();
    </script>
</x-filament-panels::layout.simple>
