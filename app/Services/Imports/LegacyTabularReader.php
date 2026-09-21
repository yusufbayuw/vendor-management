<?php

namespace App\Services\Imports;

use DomainException;
use Illuminate\Support\Str;
use ZipArchive;

class LegacyTabularReader
{
    /** @return array<int, array<string, mixed>> */
    public function rows(string $absolutePath, string $filename): array
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->csvRows($absolutePath),
            'xlsx' => $this->xlsxRows($absolutePath),
            default => throw new DomainException('Format import hanya mendukung CSV atau XLSX.'),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new DomainException('File CSV tidak dapat dibaca.');
        }

        try {
            $headers = null;
            $rows = [];
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

                $rows[] = $this->combine($headers, $row, $rowNumber);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function xlsxRows(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('Ekstensi PHP zip diperlukan untuk membaca XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new DomainException('File XLSX tidak dapat dibuka.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

            if (! is_string($sheetXml)) {
                throw new DomainException('Sheet pertama pada XLSX tidak ditemukan.');
            }

            $xml = simplexml_load_string($sheetXml);

            if ($xml === false) {
                throw new DomainException('Struktur XLSX tidak valid.');
            }

            $namespaces = $xml->getNamespaces(true);
            $main = $namespaces[''] ?? null;

            if ($main !== null) {
                $xml->registerXPathNamespace('x', $main);
                $rowNodes = $xml->xpath('//x:sheetData/x:row') ?: [];
            } else {
                $rowNodes = $xml->sheetData->row ?? [];
            }

            $headers = null;
            $rows = [];

            foreach ($rowNodes as $rowNode) {
                $values = [];
                $cells = $main !== null ? ($rowNode->xpath('./x:c') ?: []) : ($rowNode->c ?? []);

                foreach ($cells as $cell) {
                    $reference = (string) $cell['r'];
                    $columnIndex = $this->columnIndex($reference);
                    $type = (string) $cell['t'];

                    if ($main !== null) {
                        $cell->registerXPathNamespace('x', $main);
                    }

                    if ($type === 'inlineStr') {
                        $textNodes = $main !== null ? ($cell->xpath('.//x:is/x:t') ?: []) : ($cell->xpath('.//is/t') ?: []);
                        $value = implode('', array_map(static fn ($text): string => (string) $text, $textNodes));
                    } else {
                        $valueNodes = $main !== null ? ($cell->xpath('./x:v') ?: []) : ($cell->xpath('./v') ?: []);
                        $raw = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                        $value = $type === 's'
                            ? ($sharedStrings[(int) $raw] ?? '')
                            : $raw;
                    }

                    $values[$columnIndex] = trim((string) $value);
                }

                if ($values === []) {
                    continue;
                }

                ksort($values);
                $dense = [];
                $maxIndex = max(array_keys($values));

                for ($index = 0; $index <= $maxIndex; $index++) {
                    $dense[] = $values[$index] ?? '';
                }

                if ($headers === null) {
                    $headers = $this->normalizeHeaders($dense);

                    continue;
                }

                if ($this->isEmptyRow($dense)) {
                    continue;
                }

                $sourceRow = (int) ($rowNode['r'] ?? 0);
                $rows[] = $this->combine($headers, $dense, $sourceRow);
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');

        if (! is_string($xmlContent)) {
            return [];
        }

        $xml = simplexml_load_string($xmlContent);

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

    /** @param array<int, string> $headers
     *  @param array<int, mixed> $values
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
