<x-filament-panels::page>
    <div class="mx-auto w-full max-w-xl space-y-6">
        <div class="space-y-2">
            <h2 class="text-lg font-semibold">Verifikasi nomor HP</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Nomor saat ini: <strong>{{ $this->maskedPhone() }}</strong>
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Verifikasi diperlukan sebelum akun supplier dapat mengakses fitur transaksi.
            </p>
        </div>

        @if (blank(auth()->user()?->phone))
            <x-filament::section>
                <p class="text-sm text-warning-600 dark:text-warning-400">
                    Nomor HP belum tersedia. Buka Profil untuk menambahkan nomor HP terlebih dahulu.
                </p>
                <div class="mt-4">
                    <x-filament::button tag="a" href="/supplier/profile" color="gray">Buka Profil</x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($this->usesManualVerification())
            <x-filament::section>
                @if ($manualError)
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $manualError }}</p>
                @else
                    <div class="space-y-3">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Kirim pesan WhatsApp yang sudah dipersonalisasi ke admin. Admin akan mencocokkan nomor pengirim,
                            nomor terdaftar, dan kode referensi sebelum menyetujui verifikasi.
                        </p>
                        <p class="text-sm">Kode referensi: <strong>{{ $manualReference }}</strong></p>
                        <x-filament::button
                            tag="a"
                            :href="$whatsappUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            icon="heroicon-o-chat-bubble-left-right"
                        >
                            Verifikasi melalui WhatsApp
                        </x-filament::button>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Membuka WhatsApp tidak otomatis memverifikasi akun. Status berubah setelah admin menyetujui permintaan.
                        </p>
                    </div>
                @endif
            </x-filament::section>
        @else
            <form wire:submit="verify" class="space-y-6">
                {{ $this->form }}
                <div class="flex flex-wrap gap-3">
                    <x-filament::button type="submit">Verifikasi</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="sendCode">Kirim / Kirim Ulang OTP</x-filament::button>
                </div>
            </form>

            @if ($this->usesLogDriver())
                <x-filament::section>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Mode development aktif. OTP dicatat melalui Laravel log karena <code>OTP_CHANNEL=log</code>.
                    </p>
                </x-filament::section>
            @endif
        @endif

        @if (filled(auth()->user()?->email))
            <x-filament::section>
                <div class="space-y-1">
                    <p class="text-sm font-medium">Verifikasi email</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ auth()->user()?->hasVerifiedEmail()
                            ? 'Email sudah terverifikasi.'
                            : 'Link verifikasi telah dikirim otomatis ke '.auth()->user()?->email.'.' }}
                    </p>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
