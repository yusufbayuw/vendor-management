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
    class="grid gap-4 lg:grid-cols-[17rem_minmax(0,1fr)]"
>
    <div class="space-y-3">
        <div class="flex items-center justify-between px-1 text-xs text-gray-500 dark:text-gray-400">
            <span x-text="`${files.length} file`"></span>
            <span x-show="files.length > 1" x-text="`${selectedIndex + 1}/${files.length}`"></span>
        </div>

        <div class="max-h-[68vh] space-y-2 overflow-y-auto rounded-xl border border-gray-200 p-2 dark:border-gray-700">
            <template x-for="(file, index) in files" :key="index">
                <button
                    type="button"
                    class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                    :class="selectedIndex === index ? 'bg-gray-100 font-medium dark:bg-gray-800' : ''"
                    @click="select(index)"
                >
                    <span class="block truncate" x-text="file.label"></span>
                    <span class="mt-1 block truncate text-xs font-normal text-gray-500 dark:text-gray-400" x-text="file.mimeType"></span>
                </button>
            </template>
        </div>
    </div>

    <div class="space-y-3" x-show="selected">
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-700">
            <div class="min-w-0">
                <div class="truncate text-sm font-medium text-gray-950 dark:text-white" x-text="selected?.filename"></div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="selected?.mimeType"></span>
                    <span> · </span>
                    <span x-text="formatBytes(selected?.size)"></span>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <template x-if="selected?.type === 'image' && selected?.available">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = Math.max(0.5, scale - 0.25)">− Zoom</button>
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = Math.min(3, scale + 0.25)">+ Zoom</button>
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="rotation = (rotation + 90) % 360">Putar</button>
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="scale = 1; rotation = 0">Reset</button>
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="$refs.galleryViewer.requestFullscreen?.()">Layar Penuh</button>
                    </div>
                </template>

                <template x-if="files.length > 1">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="previous()">Sebelumnya</button>
                        <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800" @click="next()">Berikutnya</button>
                    </div>
                </template>
            </div>
        </div>

        <div x-ref="galleryViewer" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            <template x-if="! selected?.available">
                <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                    File tidak tersedia pada penyimpanan private atau metadata file tidak dapat dibaca.
                </div>
            </template>

            <template x-if="selected?.available && selected?.type === 'image'">
                <div class="flex min-h-80 max-h-[65vh] items-center justify-center overflow-auto p-4">
                    <img
                        :src="selected.inlineUrl"
                        :alt="`Preview ${selected.filename}`"
                        class="max-h-[60vh] max-w-full rounded-lg object-contain transition-transform duration-150"
                        :style="`transform: scale(${scale}) rotate(${rotation}deg)`"
                        referrerpolicy="no-referrer"
                    >
                </div>
            </template>

            <template x-if="selected?.available && selected?.type === 'pdf'">
                <iframe
                    :src="selected.inlineUrl"
                    :title="`Preview ${selected.filename}`"
                    class="h-[65vh] w-full"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                ></iframe>
            </template>

            <template x-if="selected?.available && selected?.type === 'download'">
                <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                    Tipe file ini tidak ditampilkan inline demi keamanan.
                </div>
            </template>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <template x-if="selected?.available && selected?.type === 'pdf'">
                <a
                    :href="selected.inlineUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                >
                    Buka Tab Baru
                </a>
            </template>

            <template x-if="selected?.available">
                <a
                    :href="selected.downloadUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="fi-btn fi-btn-size-md fi-color-primary fi-ac-btn-action"
                >
                    Download File
                </a>
            </template>
        </div>
    </div>
</div>
