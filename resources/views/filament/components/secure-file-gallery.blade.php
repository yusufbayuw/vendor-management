@php
    $normalizedFiles = collect($files ?? [])->map(function (array $file): array {
        $extension = strtolower(pathinfo((string) ($file['path'] ?? ''), PATHINFO_EXTENSION));

        return [
            'label' => (string) ($file['label'] ?? basename((string) ($file['path'] ?? 'file'))),
            'inlineUrl' => (string) ($file['inlineUrl'] ?? ''),
            'downloadUrl' => (string) ($file['downloadUrl'] ?? ''),
            'type' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
                ? 'image'
                : ($extension === 'pdf' ? 'pdf' : 'download'),
        ];
    })->values();
@endphp

<div
    x-data="{ files: @js($normalizedFiles), selected: @js($normalizedFiles->first()) }"
    class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]"
>
    <div class="max-h-[70vh] space-y-2 overflow-y-auto rounded-xl border border-gray-200 p-2 dark:border-gray-700">
        <template x-for="(file, index) in files" :key="index">
            <button
                type="button"
                class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-800"
                :class="selected === file ? 'bg-gray-100 font-medium dark:bg-gray-800' : ''"
                @click="selected = file"
            >
                <span class="block truncate" x-text="file.label"></span>
            </button>
        </template>
    </div>

    <div class="space-y-3" x-show="selected">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
            <template x-if="selected?.type === 'image'">
                <div class="flex min-h-80 items-center justify-center p-4">
                    <img
                        :src="selected.inlineUrl"
                        alt="Preview file"
                        class="max-h-[65vh] max-w-full rounded-lg object-contain"
                        referrerpolicy="no-referrer"
                    >
                </div>
            </template>

            <template x-if="selected?.type === 'pdf'">
                <iframe
                    :src="selected.inlineUrl"
                    title="Preview PDF"
                    class="h-[65vh] w-full"
                    referrerpolicy="no-referrer"
                ></iframe>
            </template>

            <template x-if="selected?.type === 'download'">
                <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                    Tipe file ini tidak ditampilkan inline demi keamanan.
                </div>
            </template>
        </div>

        <div class="flex justify-end">
            <a
                :href="selected?.downloadUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="fi-btn fi-btn-size-md fi-color-primary fi-ac-btn-action"
            >
                Download File
            </a>
        </div>
    </div>
</div>
