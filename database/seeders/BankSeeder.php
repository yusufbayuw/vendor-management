<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\SupplierBankAccount;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        // Baseline kode transfer bank Indonesia, diperbarui Oktober 2026.
        // Daftar utama mengikuti direktori Kode Bank Jaringan PRIMA 2026,
        // lalu dilengkapi bank digital/anggota jaringan lain yang umum dipakai supplier.
        $banks = [
            ['code' => '014', 'name' => 'Bank BCA'],
            ['code' => '002', 'name' => 'Bank BRI'],
            ['code' => '008', 'name' => 'Bank Mandiri'],
            ['code' => '009', 'name' => 'Bank BNI'],
            ['code' => '200', 'name' => 'Bank BTN'],
            ['code' => '451', 'name' => 'Bank Syariah Indonesia (BSI)'],
            ['code' => '022', 'name' => 'Bank CIMB Niaga'],
            ['code' => '013', 'name' => 'Bank Permata'],
            ['code' => '011', 'name' => 'Bank Danamon'],
            ['code' => '028', 'name' => 'Bank OCBC NISP'],
            ['code' => '016', 'name' => 'Bank Maybank Indonesia'],
            ['code' => '426', 'name' => 'Bank Mega'],
            ['code' => '019', 'name' => 'Bank Panin'],
            ['code' => '535', 'name' => 'SeaBank Indonesia'],
            ['code' => '542', 'name' => 'Bank Jago'],
            ['code' => '490', 'name' => 'Bank Neo Commerce'],
            ['code' => '501', 'name' => 'Bank Digital BCA (blu)'],
            ['code' => '567', 'name' => 'Allo Bank'],
            ['code' => '562', 'name' => 'Superbank'],
            ['code' => '553', 'name' => 'hibank'],
            ['code' => '494', 'name' => 'Bank Raya Indonesia'],
            ['code' => '459', 'name' => 'Krom Bank'],
            ['code' => '472', 'name' => 'Bank Saqu'],
            ['code' => '213', 'name' => 'Bank SMBC'],
            ['code' => '046', 'name' => 'Bank DBS Indonesia'],
            ['code' => '484', 'name' => 'Bank KEB Hana Indonesia'],
            ['code' => '441', 'name' => 'Bank KB Bukopin'],
            ['code' => '147', 'name' => 'Bank Muamalat'],
            ['code' => '536', 'name' => 'Bank BCA Syariah'],
            ['code' => '547', 'name' => 'Bank BTPN Syariah'],
            ['code' => '521', 'name' => 'Bank KB Syariah Bukopin'],
            ['code' => '425', 'name' => 'Bank BJB Syariah'],
            ['code' => '517', 'name' => 'Panin Dubai Syariah Bank'],
            ['code' => '506', 'name' => 'Bank Mega Syariah'],
            ['code' => '947', 'name' => 'Bank Aladin Syariah'],
            ['code' => '546', 'name' => 'Bank Nano Syariah'],
            ['code' => '405', 'name' => 'Bank Victoria Syariah'],
            ['code' => '110', 'name' => 'Bank BJB'],
            ['code' => '111', 'name' => 'Bank Jakarta'],
            ['code' => '112', 'name' => 'Bank BPD DIY'],
            ['code' => '113', 'name' => 'Bank Jateng'],
            ['code' => '114', 'name' => 'Bank Jatim'],
            ['code' => '115', 'name' => 'Bank Jambi'],
            ['code' => '116', 'name' => 'Bank Aceh Syariah'],
            ['code' => '117', 'name' => 'Bank Sumut'],
            ['code' => '118', 'name' => 'Bank Nagari'],
            ['code' => '119', 'name' => 'Bank Riau Kepri Syariah'],
            ['code' => '120', 'name' => 'Bank Sumselbabel'],
            ['code' => '121', 'name' => 'Bank Lampung'],
            ['code' => '122', 'name' => 'Bank Kalsel'],
            ['code' => '123', 'name' => 'Bank Kalbar'],
            ['code' => '124', 'name' => 'Bank Kaltimtara'],
            ['code' => '125', 'name' => 'Bank Kalteng'],
            ['code' => '126', 'name' => 'Bank Sulselbar'],
            ['code' => '127', 'name' => 'Bank Sulutgo'],
            ['code' => '128', 'name' => 'Bank NTB Syariah'],
            ['code' => '129', 'name' => 'Bank BPD Bali'],
            ['code' => '130', 'name' => 'Bank NTT'],
            ['code' => '131', 'name' => 'Bank Maluku Malut'],
            ['code' => '132', 'name' => 'Bank Papua'],
            ['code' => '133', 'name' => 'Bank Bengkulu'],
            ['code' => '134', 'name' => 'Bank Sulteng'],
            ['code' => '135', 'name' => 'Bank Sultra'],
            ['code' => '137', 'name' => 'Bank Banten'],
            ['code' => '037', 'name' => 'Bank Artha Graha Internasional'],
            ['code' => '054', 'name' => 'Bank Capital Indonesia'],
            ['code' => '076', 'name' => 'Bank Bumi Arta'],
            ['code' => '087', 'name' => 'Bank HSBC Indonesia'],
            ['code' => '095', 'name' => 'Bank JTrust Indonesia'],
            ['code' => '097', 'name' => 'Bank Mayapada'],
            ['code' => '151', 'name' => 'Bank Mestika'],
            ['code' => '152', 'name' => 'Bank Shinhan'],
            ['code' => '153', 'name' => 'Bank Sinarmas'],
            ['code' => '157', 'name' => 'KBank Indonesia'],
            ['code' => '161', 'name' => 'Bank Ganesha'],
            ['code' => '164', 'name' => 'Bank ICBC Indonesia'],
            ['code' => '167', 'name' => 'Bank QNB Indonesia'],
            ['code' => '212', 'name' => 'Bank Woori Saudara'],
            ['code' => '485', 'name' => 'Bank MNC'],
            ['code' => '498', 'name' => 'Bank SBI Indonesia'],
            ['code' => '503', 'name' => 'Bank National Nobu'],
            ['code' => '513', 'name' => 'Bank Ina Perdana'],
            ['code' => '520', 'name' => 'Prima Master Bank'],
            ['code' => '523', 'name' => 'Bank Sahabat Sampoerna'],
            ['code' => '526', 'name' => 'Bank Oke Indonesia'],
            ['code' => '531', 'name' => 'Amar Bank'],
            ['code' => '548', 'name' => 'Bank Multiarta Sentosa'],
            ['code' => '555', 'name' => 'Bank Index Selindo'],
            ['code' => '564', 'name' => 'Bank Mandiri Taspen'],
            ['code' => '566', 'name' => 'Bank Victoria'],
            ['code' => '622', 'name' => 'Bank Sleman'],
            ['code' => '945', 'name' => 'IBK Bank Indonesia'],
            ['code' => '949', 'name' => 'Bank CTBC Indonesia'],
            ['code' => '023', 'name' => 'Bank UOB Indonesia'],
            ['code' => '036', 'name' => 'Bank CCB Indonesia'],
            ['code' => '042', 'name' => 'MUFG Bank'],
            ['code' => '050', 'name' => 'Standard Chartered Bank'],
            ['code' => '069', 'name' => 'Bank of China Jakarta Branch'],
        ];

        foreach ($banks as $index => $bank) {
            Bank::query()->updateOrCreate(
                ['code' => $bank['code']],
                [
                    'name' => $bank['name'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }

        SupplierBankAccount::query()
            ->whereNull('bank_id')
            ->whereNotNull('bank_code')
            ->orderBy('id')
            ->chunkById(200, function ($accounts): void {
                $bankByCode = Bank::query()
                    ->whereIn('code', $accounts->pluck('bank_code')->filter()->unique()->values())
                    ->get()
                    ->keyBy('code');

                foreach ($accounts as $account) {
                    $bank = $bankByCode->get($account->bank_code);

                    if (! $bank) {
                        continue;
                    }

                    $account->forceFill([
                        'bank_id' => $bank->getKey(),
                        'bank_name' => $bank->name,
                    ])->saveQuietly();
                }
            });
    }
}
