@php
    $type = (string) ($file['type'] ?? 'download');
    $available = (bool) ($file['available'] ?? false);
    $filename = (string) ($file['filename'] ?? 'file');
    $mimeType = (string) ($file['mimeType'] ?? 'application/octet-stream');
    $size = $file['size'] ?? null;
    $sizeLabel = is_int($size) ? \Illuminate\Support\Number::fileSize($size, precision: 1) : 'Ukuran tidak tersedia';
@endphp

<div
    x-data="{ scale: 1, rotation: 0 }"
    class="space-y-4"
>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-700">
        <div class="min-w-0">
            <div class="truncate text-sm font-medium text-gray-950 dark:text-white" title="{{ $filename }}">
                {{ $filename }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $mimeType }} · {{ $sizeLabel }}
            </div>
        </div>

        @if ($available && $type === 'image')
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = Math.max(0.5, scale - 0.25)">
                    − Zoom
                </button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = Math.min(3, scale + 0.25)">
                    + Zoom
                </button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="rotation = (rotation + 90) % 360">
                    Putar
                </button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = 1; rotation = 0">
                    Reset
                </button>
                <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="$refs.viewer.requestFullscreen?.()">
                    Layar Penuh
                </button>
            </div>
        @endif
    </div>

    <div
        x-ref="viewer"
        class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900"
    >
        @if (! $available)
            <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                File tidak tersedia pada penyimpanan private atau metadata file tidak dapat dibaca.
            </div>
        @elseif ($type === 'image')
            <div class="flex min-h-80 max-h-[70vh] items-center justify-center overflow-auto p-4">
                <img
                    src="{{ $inlineUrl }}"
                    alt="Preview {{ $filename }}"
                    class="max-h-[65vh] max-w-full rounded-lg object-contain transition-transform duration-150"
                    :style="`transform: scale(${scale}) rotate(${rotation}deg)`"
                    referrerpolicy="no-referrer"
                >
            </div>
        @elseif ($type === 'pdf')
            <iframe
                src="{{ $inlineUrl }}"
                title="Preview {{ $filename }}"
                class="h-[70vh] w-full"
                loading="lazy"
                referrerpolicy="no-referrer"
            ></iframe>
        @else
            <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                Tipe file ini tidak ditampilkan inline demi keamanan. Gunakan tombol download untuk membukanya.
            </div>
        @endif
    </div>

    <div class="flex flex-wrap justify-end gap-2">
        @if ($available && $type === 'pdf')
            <x-filament::button
                tag="a"
                :href="$inlineUrl"
                target="_blank"
                rel="noopener noreferrer"
                color="gray"
                icon="heroicon-o-arrow-top-right-on-square"
            >
                Buka Tab Baru
            </x-filament::button>
        @endif

        @if ($available)
            <x-filament::button
                tag="a"
                :href="$downloadUrl"
                target="_blank"
                rel="noopener noreferrer"
                icon="heroicon-o-arrow-down-tray"
            >
                Download File
            </x-filament::button>
        @endif
    </div>
</div>
