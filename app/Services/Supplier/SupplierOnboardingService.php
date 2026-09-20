<?php

namespace App\Services\Supplier;

use App\Models\Supplier;

class SupplierOnboardingService
{
    /**
     * @return array{
     *     percentage:int,
     *     completed:int,
     *     total:int,
     *     complete:bool,
     *     missing:array<int,string>,
     *     sections:array{profile:bool,documents:bool,bank:bool,products:bool}
     * }
     */
    public function summary(Supplier $supplier): array
    {
        $supplier->loadMissing(['documents', 'bankAccounts', 'products']);

        $exemptions = (array) ($supplier->onboarding_exemptions ?? []);
        $formalEntity = in_array($supplier->supplier_type, ['company', 'cooperative'], true);

        $checks = [
            'legal_name' => [
                'label' => 'nama legal supplier',
                'complete' => filled($supplier->legal_name),
            ],
            'supplier_type' => [
                'label' => 'jenis supplier',
                'complete' => filled($supplier->supplier_type),
            ],
            'phone' => [
                'label' => 'nomor HP / telepon',
                'complete' => filled($supplier->phone),
            ],
            'address' => [
                'label' => 'alamat lengkap',
                'complete' => filled($supplier->address),
            ],
            'province' => [
                'label' => 'provinsi',
                'complete' => filled($supplier->province_code),
            ],
            'regency' => [
                'label' => 'kabupaten / kota',
                'complete' => filled($supplier->regency_code),
            ],
            'district' => [
                'label' => 'kecamatan',
                'complete' => filled($supplier->district_code),
            ],
            'village' => [
                'label' => 'desa / kelurahan',
                'complete' => filled($supplier->village_code),
            ],
            'npwp' => [
                'label' => 'NPWP atau deklarasi tidak memiliki',
                'complete' => filled($supplier->npwp) || (bool) ($exemptions['npwp'] ?? false),
            ],
        ];

        if ($formalEntity) {
            $checks['nib'] = [
                'label' => 'NIB atau deklarasi tidak berlaku',
                'complete' => filled($supplier->nib) || (bool) ($exemptions['nib'] ?? false),
            ];
        }

        $checks['legal_document'] = [
            'label' => 'dokumen legal pendukung',
            'complete' => $supplier->documents->contains(
                static fn ($document): bool => filled($document->file_path),
            ),
        ];
        $checks['bank_account'] = [
            'label' => 'rekening bank pembayaran',
            'complete' => $supplier->bankAccounts->contains(
                static fn ($account): bool => filled($account->bank_name)
                    && filled($account->account_number)
                    && filled($account->account_holder),
            ),
        ];
        $checks['products'] = [
            'label' => 'minimal satu komoditas / produk',
            'complete' => $supplier->products->contains(
                static fn ($product): bool => filled($product->product_id),
            ),
        ];

        $completed = collect($checks)->filter(
            static fn (array $item): bool => $item['complete'],
        )->count();
        $total = count($checks);
        $missing = collect($checks)
            ->reject(static fn (array $item): bool => $item['complete'])
            ->pluck('label')
            ->values()
            ->all();

        $profileKeys = array_values(array_diff(
            array_keys($checks),
            ['legal_document', 'bank_account', 'products'],
        ));

        $profileComplete = collect($checks)
            ->only($profileKeys)
            ->every(static fn (array $item): bool => $item['complete']);

        return [
            'percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 100,
            'completed' => $completed,
            'total' => $total,
            'complete' => $completed === $total,
            'missing' => $missing,
            'sections' => [
                'profile' => $profileComplete,
                'documents' => $checks['legal_document']['complete'],
                'bank' => $checks['bank_account']['complete'],
                'products' => $checks['products']['complete'],
            ],
        ];
    }
}
