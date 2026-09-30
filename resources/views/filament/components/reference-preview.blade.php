<style>
    .reference-preview {
        display: grid;
        gap: 1.25rem;
    }

    .reference-preview__summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .75rem;
    }

    .reference-preview__field {
        min-width: 0;
        padding: .9rem 1rem;
        border: 1px solid #e5e7eb;
        border-radius: .85rem;
        background: #f9fafb;
    }

    .reference-preview__label {
        margin: 0 0 .35rem;
        color: #6b7280;
        font-size: .75rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .reference-preview__value {
        margin: 0;
        overflow-wrap: anywhere;
        color: #111827;
        font-size: .95rem;
        font-weight: 650;
        line-height: 1.45;
    }

    .reference-preview__status {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: .22rem .55rem;
        border-radius: 999px;
        background: #fef3c7;
        color: #92400e;
        font-size: .78rem;
        font-weight: 700;
    }

    .reference-preview__section {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: .9rem;
        background: #ffffff;
    }

    .reference-preview__section-title {
        padding: .85rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
        color: #111827;
        font-size: .875rem;
        font-weight: 700;
    }

    .reference-preview__table-wrap {
        overflow-x: auto;
    }

    .reference-preview__table {
        width: 100%;
        min-width: 36rem;
        border-collapse: collapse;
        text-align: left;
        font-size: .875rem;
    }

    .reference-preview__table th {
        padding: .7rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        background: #ffffff;
        color: #6b7280;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .01em;
        white-space: nowrap;
    }

    .reference-preview__table td {
        padding: .8rem 1rem;
        border-bottom: 1px solid #f3f4f6;
        color: #374151;
        line-height: 1.4;
        vertical-align: top;
    }

    .reference-preview__table tbody tr:last-child td {
        border-bottom: 0;
    }

    .reference-preview__table .is-number {
        text-align: right;
        white-space: nowrap;
    }

    .reference-preview__footer {
        display: flex;
        justify-content: flex-end;
        gap: .75rem;
        padding-top: .1rem;
    }

    .reference-preview__missing {
        padding: 1rem;
        border: 1px solid #fde68a;
        border-radius: .85rem;
        background: #fffbeb;
        color: #92400e;
        font-size: .875rem;
        line-height: 1.5;
    }

    html.dark .reference-preview__field {
        border-color: #374151;
        background: rgba(31, 41, 55, .55);
    }

    html.dark .reference-preview__label {
        color: #9ca3af;
    }

    html.dark .reference-preview__value {
        color: #f9fafb;
    }

    html.dark .reference-preview__status {
        background: rgba(245, 158, 11, .16);
        color: #fbbf24;
    }

    html.dark .reference-preview__section {
        border-color: #374151;
        background: rgba(17, 24, 39, .45);
    }

    html.dark .reference-preview__section-title,
    html.dark .reference-preview__table th {
        border-color: #374151;
        background: rgba(31, 41, 55, .7);
        color: #e5e7eb;
    }

    html.dark .reference-preview__table td {
        border-color: rgba(55, 65, 81, .7);
        color: #d1d5db;
    }

    html.dark .reference-preview__missing {
        border-color: #92400e;
        background: rgba(120, 53, 15, .22);
        color: #fcd34d;
    }

    @media (max-width: 900px) {
        .reference-preview__summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .reference-preview__summary {
            grid-template-columns: minmax(0, 1fr);
        }

        .reference-preview__field {
            padding: .8rem .9rem;
        }

        .reference-preview__footer {
            justify-content: stretch;
        }

        .reference-preview__footer > * {
            width: 100%;
        }
    }
</style>

<div class="reference-preview">
    @if ($missing ?? false)
        <div class="reference-preview__missing">
            Referensi {{ $type }} tidak tersedia atau sudah tidak dapat diakses.
        </div>
    @else
        <dl class="reference-preview__summary">
            @foreach (($fields ?? []) as $label => $value)
                <div class="reference-preview__field">
                    <dt class="reference-preview__label">{{ $label }}</dt>
                    <dd class="reference-preview__value">
                        @if ($label === 'Status')
                            <span class="reference-preview__status">{{ filled($value) ? $value : '-' }}</span>
                        @else
                            {{ filled($value) ? $value : '-' }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if (! empty($items))
            <section class="reference-preview__section">
                <div class="reference-preview__section-title">Item</div>

                <div class="reference-preview__table-wrap">
                    <table class="reference-preview__table">
                        <thead>
                            <tr>
                                @foreach (array_keys($items[0] ?? []) as $heading)
                                    @php
                                        $isNumeric = in_array($heading, ['Jumlah', 'Estimasi', 'Harga', 'Subtotal', 'Total'], true);
                                    @endphp
                                    <th class="{{ $isNumeric ? 'is-number' : '' }}">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $row)
                                <tr>
                                    @foreach ($row as $heading => $value)
                                        @php
                                            $isNumeric = in_array($heading, ['Jumlah', 'Estimasi', 'Harga', 'Subtotal', 'Total'], true);
                                        @endphp
                                        <td class="{{ $isNumeric ? 'is-number' : '' }}">{{ filled($value) ? $value : '-' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if (filled($url ?? null))
            <div class="reference-preview__footer">
                <x-filament::button
                    tag="a"
                    :href="$url"
                    icon="heroicon-o-arrow-top-right-on-square"
                >
                    Buka Halaman Detail
                </x-filament::button>
            </div>
        @endif
    @endif
</div>
