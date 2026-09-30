<x-filament-panels::layout.simple>
    @include('filament.auth.login-background')

    <style>
        .unified-login {
            display: grid;
            width: 100%;
            gap: 1.5rem;
        }

        .unified-login__form {
            display: grid;
            gap: 1.15rem;
        }

        .unified-login__remember {
            display: flex;
            align-items: center;
            gap: .7rem;
            margin-top: -.1rem;
            color: #374151;
            font-size: .875rem;
            font-weight: 500;
        }

        .unified-login__captcha {
            display: grid;
            gap: .75rem;
        }

        .unified-login__captcha-preview {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        .unified-login__captcha-image {
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
            border: 1px solid #d1d5db;
            border-radius: .7rem;
            background: #f9fafb;
        }

        .unified-login__captcha-image img {
            display: block;
            width: 100%;
            height: auto;
            max-height: 6rem;
            object-fit: contain;
        }

        .unified-login__links {
            display: grid;
            gap: .45rem;
            text-align: center;
            color: #6b7280;
            font-size: .875rem;
        }

        .unified-login__links p {
            margin: 0;
        }

        .unified-login__link {
            font-weight: 650;
            color: rgb(var(--primary-600));
        }

        .unified-login__link:hover {
            text-decoration: underline;
        }

        html.dark .unified-login__remember {
            color: #e5e7eb;
        }

        html.dark .unified-login__captcha-image {
            border-color: #4b5563;
            background: #111827;
        }

        html.dark .unified-login__links {
            color: #9ca3af;
        }

        html.dark .unified-login__link {
            color: rgb(var(--primary-400));
        }

        @media (max-width: 520px) {
            .unified-login__form {
                gap: 1rem;
            }

            .unified-login__captcha-preview {
                align-items: stretch;
            }
        }
    </style>

    <div class="fi-simple-page">
        <section class="unified-login">
            <x-filament-panels::header.simple
                heading="Masuk ke SPPG Vendor Management"
                :logo="true"
                :subheading="null"
            />

            <form method="POST" action="{{ route('login.store', absolute: false) }}" class="unified-login__form">
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

                <label class="unified-login__remember">
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
                    <div class="unified-login__captcha">
                        <div class="unified-login__captcha-preview">
                            <div class="unified-login__captcha-image">
                                <img
                                    id="unified-login-captcha-image"
                                    src="{{ $captcha['image_light'] }}"
                                    alt="Kode keamanan"
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
                    </div>
                </x-filament-forms::field-wrapper>

                <x-filament::button type="submit">
                    Masuk
                </x-filament::button>
            </form>

            <div class="unified-login__links">
                <a
                    href="{{ url('/supplier/password-reset/request') }}"
                    class="unified-login__link"
                >
                    Lupa kata sandi?
                </a>

                <p>
                    Belum menjadi supplier?
                    <a
                        href="{{ url('/supplier/register') }}"
                        class="unified-login__link"
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
