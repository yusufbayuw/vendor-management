@php
    $type = (string) ($file['type'] ?? 'download');
    $available = (bool) ($file['available'] ?? false);
    $filename = (string) ($file['filename'] ?? 'file');
    $mimeType = (string) ($file['mimeType'] ?? 'application/octet-stream');
    $size = $file['size'] ?? null;
    $sizeLabel = is_int($size) ? \Illuminate\Support\Number::fileSize($size, precision: 1) : 'Ukuran tidak tersedia';
@endphp

<style>
    .secure-preview {
        display: grid;
        gap: 1rem;
    }

    .secure-preview__toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .85rem;
        padding: .85rem 1rem;
        border: 1px solid #e5e7eb;
        border-radius: .85rem;
        background: #f9fafb;
    }

    .secure-preview__meta {
        min-width: 0;
    }

    .secure-preview__filename {
        overflow: hidden;
        color: #111827;
        font-size: .9rem;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .secure-preview__details {
        margin-top: .25rem;
        color: #6b7280;
        font-size: .75rem;
    }

    .secure-preview__tools {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
    }

    .secure-preview__tool {
        padding: .42rem .68rem;
        border: 1px solid #d1d5db;
        border-radius: .55rem;
        background: #ffffff;
        color: #374151;
        cursor: pointer;
        font-size: .75rem;
        font-weight: 650;
    }

    .secure-preview__tool:hover {
        background: #f3f4f6;
    }

    .secure-preview__viewer {
        overflow: hidden;
        min-height: 20rem;
        border: 1px solid #e5e7eb;
        border-radius: .85rem;
        background: #f9fafb;
    }

    .secure-preview__image-wrap {
        display: flex;
        min-height: 20rem;
        max-height: 70vh;
        align-items: center;
        justify-content: center;
        overflow: auto;
        padding: 1rem;
    }

    .secure-preview__image {
        max-width: 100%;
        max-height: 65vh;
        border-radius: .55rem;
        object-fit: contain;
        transition: transform .15s ease;
    }

    .secure-preview__iframe {
        width: 100%;
        height: 70vh;
        border: 0;
    }

    .secure-preview__empty {
        display: flex;
        min-height: 20rem;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        color: #4b5563;
        text-align: center;
        font-size: .875rem;
    }

    .secure-preview__footer {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .5rem;
    }

    html.dark .secure-preview__toolbar,
    html.dark .secure-preview__viewer {
        border-color: #374151;
        background: rgba(17, 24, 39, .6);
    }

    html.dark .secure-preview__filename {
        color: #f9fafb;
    }

    html.dark .secure-preview__details,
    html.dark .secure-preview__empty {
        color: #9ca3af;
    }

    html.dark .secure-preview__tool {
        border-color: #4b5563;
        background: #1f2937;
        color: #e5e7eb;
    }

    html.dark .secure-preview__tool:hover {
        background: #374151;
    }
</style>

<div
    x-data="{ scale: 1, rotation: 0 }"
    class="secure-preview"
>
    <div class="secure-preview__toolbar">
        <div class="secure-preview__meta">
            <div class="secure-preview__filename" title="{{ $filename }}">
                {{ $filename }}
            </div>
            <div class="secure-preview__details">
                {{ $mimeType }} · {{ $sizeLabel }}
            </div>
        </div>

        @if ($available && $type === 'image')
            <div class="secure-preview__tools">
                <button type="button" class="secure-preview__tool" @click="scale = Math.max(0.5, scale - 0.25)">− Zoom</button>
                <button type="button" class="secure-preview__tool" @click="scale = Math.min(3, scale + 0.25)">+ Zoom</button>
                <button type="button" class="secure-preview__tool" @click="rotation = (rotation + 90) % 360">Putar</button>
                <button type="button" class="secure-preview__tool" @click="scale = 1; rotation = 0">Reset</button>
                <button type="button" class="secure-preview__tool" @click="$refs.viewer.requestFullscreen?.()">Layar Penuh</button>
            </div>
        @endif
    </div>

    <div x-ref="viewer" class="secure-preview__viewer">
        @if (! $available)
            <div class="secure-preview__empty">
                File tidak tersedia pada penyimpanan private atau metadata file tidak dapat dibaca.
            </div>
        @elseif ($type === 'image')
            <div class="secure-preview__image-wrap">
                <img
                    src="{{ $inlineUrl }}"
                    alt="Preview {{ $filename }}"
                    class="secure-preview__image"
                    :style="`transform: scale(${scale}) rotate(${rotation}deg)`"
                    referrerpolicy="no-referrer"
                >
            </div>
        @elseif ($type === 'pdf')
            <iframe
                src="{{ $inlineUrl }}"
                title="Preview {{ $filename }}"
                class="secure-preview__iframe"
                loading="lazy"
                referrerpolicy="no-referrer"
            ></iframe>
        @else
            <div class="secure-preview__empty">
                Tipe file ini tidak ditampilkan inline demi keamanan. Gunakan tombol download untuk membukanya.
            </div>
        @endif
    </div>

    <div class="secure-preview__footer">
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
