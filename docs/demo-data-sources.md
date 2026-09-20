# Sumber Data Demo Publik

Seeder demo memisahkan **data referensi publik** dari **data transaksi sintetis**.

## Referensi BGN — SPPG

Sumber: Badan Gizi Nasional, daftar SPPG operasional.

- https://www.bgn.go.id/operasional-sppg
- Snapshot yang digunakan: daftar publik yang terindeks per 6 Juni 2026.
- Contoh yang dimasukkan ke demo: SPPG Kota Bandung Cibeunying Kaler Sukaluyu, SPPG Kota Bandung Coblong Lebak Gede 3, dan SPPG Bandung Margahayu Sulaiman 2.
- Record referensi memakai prefix kode `REF-SPPG-` dan organisasi `REF-BGN`.

Data ini hanya dipakai untuk membuat demo terasa realistis. Pengelompokan di bawah organisasi `Referensi Publik SPPG BGN (Demo)` bukan klaim bahwa ketiga SPPG tersebut berada di bawah satu badan hukum yang sama.

## Referensi Badan Pangan Nasional — Komoditas

Sumber: Open Data Badan Pangan Nasional, *Rata-rata Harga Pangan Bulanan Tingkat Konsumen Nasional (September 2025)*.

- https://data.badanpangan.go.id/datasetpublications/2hh/rata-rata-harga-pangan-bulanan-konsumen-nasional
- Harga disimpan sebagai teks benchmark historis pada deskripsi produk referensi, bukan sebagai harga supplier aktif.
- Record referensi memakai prefix kode `REF-BPN-`.

Hal ini disengaja karena harga konsumen nasional tidak boleh diperlakukan sebagai quotation procurement dari supplier tertentu.

## Data sintetis

Akun user demo, supplier, NPWP/NIB placeholder, rekening bank, invoice, pembayaran, purchase request, purchase order, delivery, dan dokumen bukti tetap **sintetis**. Data tersebut dibuat untuk menguji workflow end-to-end dan tidak merepresentasikan individu atau badan usaha nyata.
