<div class="space-y-5">
    @if ($missing ?? false)
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-300">
            Referensi {{ $type }} tidak tersedia atau sudah tidak dapat diakses.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (($fields ?? []) as $label => $value)
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">
                        {{ filled($value) ? $value : '-' }}
                    </div>
                </div>
            @endforeach
        </div>

        @if (! empty($items))
            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold text-gray-950 dark:border-gray-700 dark:text-white">
                    Item
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                            <tr>
                                @foreach (array_keys($items[0] ?? []) as $heading)
                                    <th class="px-4 py-2 font-medium">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($items as $row)
                                <tr>
                                    @foreach ($row as $value)
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ filled($value) ? $value : '-' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (filled($url ?? null))
            <div class="flex justify-end">
                <x-filament::button
                    tag="a"
                    :href="$url"
                    icon="heroicon-o-arrow-top-right-on-square"
                >
                    Buka Detail
                </x-filament::button>
            </div>
        @endif
    @endif
</div>
