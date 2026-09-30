@php
    $normalizedFiles = collect($files ?? [])->map(function (array $file): array {
        return [
            'label' => (string) ($file['label'] ?? $file['filename'] ?? 'File'),
            'filename' => (string) ($file['filename'] ?? 'file'),
            'inlineUrl' => (string) ($file['inlineUrl'] ?? ''),
            'downloadUrl' => (string) ($file['downloadUrl'] ?? ''),
            'type' => (string) ($file['type'] ?? 'download'),
            'mimeType' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
            'size' => $file['size'] ?? null,
            'available' => (bool) ($file['available'] ?? false),
        ];
    })->values()->all();
@endphp

<style>
    .secure-gallery {
        display: grid;
        grid-template-columns: 17rem minmax(0, 1fr);
        gap: 1rem;
    }

    .secure-gallery__sidebar,
    .secure-gallery__content {
        min-width: 0;
    }

    .secure-gallery__sidebar {
        display: grid;
        align-content: start;
        gap: .65rem;
    }

    .secure-gallery__counter {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 .15rem;
        color: #6b7280;
        font-size: .75rem;
    }

    .secure-gallery__list {
        display: grid;
        gap: .4rem;
        max-height: 68vh;
        overflow-y: auto;
        padding: .45rem;
        border: 1px solid #e5e7eb;
        border-radius: .8rem;
        background: #ffffff;
    }

    .secure-gallery__item {
        display: block;
        width: 100%;
        padding: .7rem .75rem;
        border: 0;
        border-radius: .6rem;
        background: transparent;
        color: #374151;
        cursor: pointer;
        text-align: left;
    }

    .secure-gallery__item:hover,
    .secure-gallery__item.is-active {
        background: #f3f4f6;
    }

    .secure-gallery__item.is-active {
        color: #111827;
        font-weight: 700;
    }

    .secure-gallery__item-label,
    .secure-gallery__item-meta {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .secure-gallery__item-label {
        font-size: .85rem;
    }

    .secure-gallery__item-meta {
        margin-top: .2rem;
        color: #6b7280;
        font-size: .72rem;
        font-weight: 400;
    }

    .secure-gallery__content {
        display: grid;
        align-content: start;
        gap: .75rem;
    }

    .secure-gallery__toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .8rem .95rem;
        border: 1px solid #e5e7eb;
        border-radius: .8rem;
        background: #f9fafb;
    }

    .secure-gallery__filename {
        overflow: hidden;
        color: #111827;
        font-size: .88rem;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .secure-gallery__meta {
        margin-top: .22rem;
        color: #6b7280;
        font-size: .72rem;
    }

    .secure-gallery__tools {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }

    .secure-gallery__tool {
        padding: .4rem .62rem;
        border: 1px solid #d1d5db;
        border-radius: .52rem;
        background: #ffffff;
        color: #374151;
        cursor: pointer;
        font-size: .72rem;
        font-weight: 650;
    }

    .secure-gallery__tool:hover {
        background: #f3f4f6;
    }

    .secure-gallery__viewer {
        overflow: hidden;
        min-height: 20rem;
        border: 1px solid #e5e7eb;
        border-radius: .8rem;
        background: #f9fafb;
    }

    .secure-gallery__image-wrap {
        display: flex;
        min-height: 20rem;
        max-height: 65vh;
        align-items: center;
        justify-content: center;
        overflow: auto;
        padding: 1rem;
    }

    .secure-gallery__image {
        max-width: 100%;
        max-height: 60vh;
        border-radius: .5rem;
        object-fit: contain;
        transition: transform .15s ease;
    }

    .secure-gallery__iframe {
        width: 100%;
        height: 65vh;
        border: 0;
    }

    .secure-gallery__empty {
        display: flex;
        min-height: 20rem;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        color: #4b5563;
        text-align: center;
        font-size: .875rem;
    }

    .secure-gallery__footer {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .5rem;
    }

    html.dark .secure-gallery__counter,
    html.dark .secure-gallery__item-meta,
    html.dark .secure-gallery__meta,
    html.dark .secure-gallery__empty {
        color: #9ca3af;
    }

    html.dark .secure-gallery__list {
        border-color: #374151;
        background: rgba(17, 24, 39, .45);
    }

    html.dark .secure-gallery__item {
        color: #d1d5db;
    }

    html.dark .secure-gallery__item:hover,
    html.dark .secure-gallery__item.is-active {
        background: #1f2937;
    }

    html.dark .secure-gallery__item.is-active,
    html.dark .secure-gallery__filename {
        color: #f9fafb;
    }

    html.dark .secure-gallery__toolbar,
    html.dark .secure-gallery__viewer {
        border-color: #374151;
        background: rgba(17, 24, 39, .6);
    }

    html.dark .secure-gallery__tool {
        border-color: #4b5563;
        background: #1f2937;
        color: #e5e7eb;
    }

    html.dark .secure-gallery__tool:hover {
        background: #374151;
    }

    @media (max-width: 900px) {
        .secure-gallery {
            grid-template-columns: 1fr;
        }

        .secure-gallery__list {
            max-height: 14rem;
        }
    }
</style>

<div
    x-data="{
        files: @js($normalizedFiles),
        selectedIndex: 0,
        scale: 1,
        rotation: 0,
        get selected() { return this.files[this.selectedIndex] ?? null },
        select(index) { this.selectedIndex = index; this.scale = 1; this.rotation = 0 },
        previous() { if (this.files.length > 1) this.select((this.selectedIndex - 1 + this.files.length) % this.files.length) },
        next() { if (this.files.length > 1) this.select((this.selectedIndex + 1) % this.files.length) },
        formatBytes(bytes) {
            if (bytes === null || bytes === undefined) return 'Ukuran tidak tersedia'
            if (bytes < 1024) return `${bytes} B`
            if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`
            return `${(bytes / 1048576).toFixed(1)} MB`
        },
    }"
    @keydown.left.window="previous()"
    @keydown.right.window="next()"
    class="secure-gallery"
>
    <aside class="secure-gallery__sidebar">
        <div class="secure-gallery__counter">
            <span x-text="`${files.length} file`"></span>
            <span x-show="files.length > 1" x-text="`${selectedIndex + 1}/${files.length}`"></span>
        </div>

        <div class="secure-gallery__list">
            <template x-for="(file, index) in files" :key="index">
                <button
                    type="button"
                    class="secure-gallery__item"
                    :class="{ 'is-active': selectedIndex === index }"
                    @click="select(index)"
                >
                    <span class="secure-gallery__item-label" x-text="file.label"></span>
                    <span class="secure-gallery__item-meta" x-text="file.mimeType"></span>
                </button>
            </template>
        </div>
    </aside>

    <section class="secure-gallery__content" x-show="selected">
        <div class="secure-gallery__toolbar">
            <div style="min-width:0">
                <div class="secure-gallery__filename" x-text="selected?.filename"></div>
                <div class="secure-gallery__meta">
                    <span x-text="selected?.mimeType"></span>
                    <span> · </span>
                    <span x-text="formatBytes(selected?.size)"></span>
                </div>
            </div>

            <div class="secure-gallery__tools">
                <template x-if="selected?.type === 'image' && selected?.available">
                    <div class="secure-gallery__tools">
                        <button type="button" class="secure-gallery__tool" @click="scale = Math.max(0.5, scale - 0.25)">− Zoom</button>
                        <button type="button" class="secure-gallery__tool" @click="scale = Math.min(3, scale + 0.25)">+ Zoom</button>
                        <button type="button" class="secure-gallery__tool" @click="rotation = (rotation + 90) % 360">Putar</button>
                        <button type="button" class="secure-gallery__tool" @click="scale = 1; rotation = 0">Reset</button>
                        <button type="button" class="secure-gallery__tool" @click="$refs.galleryViewer.requestFullscreen?.()">Layar Penuh</button>
                    </div>
                </template>

                <template x-if="files.length > 1">
                    <div class="secure-gallery__tools">
                        <button type="button" class="secure-gallery__tool" @click="previous()">Sebelumnya</button>
                        <button type="button" class="secure-gallery__tool" @click="next()">Berikutnya</button>
                    </div>
                </template>
            </div>
        </div>

        <div x-ref="galleryViewer" class="secure-gallery__viewer">
            <template x-if="! selected?.available">
                <div class="secure-gallery__empty">
                    File tidak tersedia pada penyimpanan private atau metadata file tidak dapat dibaca.
                </div>
            </template>

            <template x-if="selected?.available && selected?.type === 'image'">
                <div class="secure-gallery__image-wrap">
                    <img
                        :src="selected.inlineUrl"
                        :alt="`Preview ${selected.filename}`"
                        class="secure-gallery__image"
                        :style="`transform: scale(${scale}) rotate(${rotation}deg)`"
                        referrerpolicy="no-referrer"
                    >
                </div>
            </template>

            <template x-if="selected?.available && selected?.type === 'pdf'">
                <iframe
                    :src="selected.inlineUrl"
                    :title="`Preview ${selected.filename}`"
                    class="secure-gallery__iframe"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                ></iframe>
            </template>

            <template x-if="selected?.available && selected?.type === 'download'">
                <div class="secure-gallery__empty">
                    Tipe file ini tidak ditampilkan inline demi keamanan.
                </div>
            </template>
        </div>

        <div class="secure-gallery__footer">
            <template x-if="selected?.available && selected?.type === 'pdf'">
                <a
                    :href="selected.inlineUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="secure-gallery__tool"
                >
                    Buka Tab Baru
                </a>
            </template>

            <template x-if="selected?.available">
                <a
                    :href="selected.downloadUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="secure-gallery__tool"
                >
                    Download File
                </a>
            </template>
        </div>
    </section>
</div>
