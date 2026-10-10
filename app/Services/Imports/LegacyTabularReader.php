<?php

namespace App\Services\Imports;

use DomainException;
use Illuminate\Support\Str;
use Generator;
use XMLReader;
use ZipArchive;

class LegacyTabularReader
{
    public const MAX_ROWS = 10000;

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const MAX_SHEET_BYTES = 40 * 1024 * 1024;

    private const MAX_SHARED_STRINGS_BYTES = 8 * 1024 * 1024;

    /** @return array<int, array<string, mixed>> */
    public function rows(string $absolutePath, string $filename): array
    {
        return iterator_to_array($this->streamRows($absolutePath, $filename), false);
    }

    /** @return Generator<int, array<string, mixed>> */
    public function streamRows(string $absolutePath, string $filename): Generator
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        $size = @filesize($absolutePath);
        if (! is_int($size) || $size > self::MAX_FILE_BYTES) {
            throw new DomainException('File import tidak ditemukan atau melebihi batas 20 MB.');
        }

        yield from match ($extension) {
            'csv' => $this->csvRows($absolutePath),
            'xlsx' => $this->xlsxRows($absolutePath),
            default => throw new DomainException('Format import hanya mendukung CSV atau XLSX.'),
        };
    }

    /** @return Generator<int, array<string, mixed>> */
    private function csvRows(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('File CSV tidak dapat dibaca.');
        }

        try {
            $headers = null;
            $rowNumber = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($row);

                    continue;
                }

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                yield $this->combine($headers, $row, $rowNumber);
            }

        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function xlsxRows(string $path): Generator
    {
        if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
            throw new DomainException('Ekstensi PHP zip dan XMLReader diperlukan untuk membaca XLSX.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new DomainException('File XLSX tidak dapat dibuka.');
        }

        try {
            $sheet = $zip->statName('xl/worksheets/sheet1.xml');
            $strings = $zip->statName('xl/sharedStrings.xml');

            if ($sheet === false || ($sheet['size'] ?? 0) > self::MAX_SHEET_BYTES
                || ($strings !== false && ($strings['size'] ?? 0) > self::MAX_SHARED_STRINGS_BYTES)) {
                throw new DomainException('Sheet XLSX hilang atau melebihi batas ukuran aman.');
            }

            $sharedStrings = $this->sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $reader = new XMLReader;
        $uri = 'zip://'.$path.'#xl/worksheets/sheet1.xml';

        if (! $reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new DomainException('Sheet XLSX tidak dapat dibaca.');
        }

        try {
            $headers = null;
            $sourceRow = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $sourceRow = (int) ($reader->getAttribute('r') ?: ($sourceRow + 1));
                $fragment = $reader->readOuterXml();

                if (strlen($fragment) > 128 * 1024) {
                    throw new DomainException('Baris XLSX terlalu besar.');
                }

                $rowNode = simplexml_load_string($fragment, 'SimpleXMLElement', LIBXML_NONET);

                if ($rowNode === false) {
                    throw new DomainException('Struktur baris XLSX tidak valid.');
                }

                $values = [];
                $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cells = $rowNode->xpath('./x:c') ?: [];

                foreach ($cells as $cell) {
                    $columnIndex = $this->columnIndex((string) $cell['r']);
                    if ($columnIndex >= 200) {
                        throw new DomainException('XLSX memiliki terlalu banyak kolom.');
                    }

                    $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $type = (string) $cell['t'];

                    if ($type === 'inlineStr') {
                        $texts = $cell->xpath('.//x:is/x:t') ?: [];
                        $value = implode('', array_map(static fn ($text): string => (string) $text, $texts));
                    } else {
                        $nodes = $cell->xpath('./x:v') ?: [];
                        $raw = isset($nodes[0]) ? (string) $nodes[0] : '';
                        $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                    }

                    $values[$columnIndex] = trim((string) $value);
                }

                if ($values === []) {
                    continue;
                }

                ksort($values);
                $dense = [];
                for ($index = 0; $index <= max(array_keys($values)); $index++) {
                    $dense[] = $values[$index] ?? '';
                }

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($dense);

                    continue;
                }

                if (! $this->isEmptyRow($dense)) {
                    yield $this->combine($headers, $dense, $sourceRow);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xmlContent)) {
            return [];
        }

        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);

        if ($xml === false) {
            return [];
        }

        $namespaces = $xml->getNamespaces(true);
        $main = $namespaces[''] ?? null;

        if ($main !== null) {
            $xml->registerXPathNamespace('x', $main);
            $items = $xml->xpath('//x:si') ?: [];
        } else {
            $items = $xml->si ?? [];
        }

        $strings = [];

        foreach ($items as $item) {
            $parts = [];

            if ($main !== null) {
                $item->registerXPathNamespace('x', $main);
                foreach ($item->xpath('.//x:t') ?: [] as $text) {
                    $parts[] = (string) $text;
                }
            } else {
                foreach ($item->xpath('.//t') ?: [] as $text) {
                    $parts[] = (string) $text;
                }
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /** @param array<int, mixed> $headers */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(
            static fn ($header): string => Str::snake(trim((string) $header)),
            $headers,
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $values
     */
    private function combine(array $headers, array $values, int $rowNumber): array
    {
        $row = ['_source_row' => $rowNumber];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $values[$index] ?? null;
            $row[$header] = is_string($value) ? trim($value) : $value;
        }

        return $row;
    }

    /** @param array<int, mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (filled($value)) {
                return false;
            }
        }

        return true;
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
