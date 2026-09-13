@php
    $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    $isPdf = $extension === 'pdf';
@endphp

<div class="space-y-4">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
        @if ($isImage)
            <div class="flex min-h-80 items-center justify-center p-4">
                <img
                    src="{{ $inlineUrl }}"
                    alt="Preview file"
                    class="max-h-[70vh] max-w-full rounded-lg object-contain"
                    referrerpolicy="no-referrer"
                >
            </div>
        @elseif ($isPdf)
            <iframe
                src="{{ $inlineUrl }}"
                title="Preview PDF"
                class="h-[70vh] w-full"
                referrerpolicy="no-referrer"
            ></iframe>
        @else
            <div class="p-8 text-center text-sm text-gray-600 dark:text-gray-300">
                Tipe file ini tidak ditampilkan inline demi keamanan. Gunakan tombol download untuk membukanya.
            </div>
        @endif
    </div>

    <div class="flex justify-end">
        <x-filament::button
            tag="a"
            :href="$downloadUrl"
            target="_blank"
            rel="noopener noreferrer"
            icon="heroicon-o-arrow-down-tray"
        >
            Download File
        </x-filament::button>
    </div>
</div>
