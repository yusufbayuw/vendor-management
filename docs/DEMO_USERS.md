# Demo Users

Akun pada dokumen ini hanya dibuat oleh `DatabaseSeeder` ketika environment **bukan** `production`.

- Password seluruh akun demo: `password`
- Email menggunakan domain `example.test` dan tidak ditujukan untuk produksi.
- Akun `role.*` dibuat dengan **satu role saja** agar pengujian permission E2E tidak tercampur oleh role lain.
- Akun skenario lama tetap dipertahankan untuk menjalankan seeded procure-to-pay flow.

## Akun role-isolated internal

| Username | Email | Role | Scope |
|---|---|---|---|
| `role.superadmin` | `role.superadmin@example.test` | `super_admin` | Global |
| `role.panel` | `role.panel@example.test` | `panel_user` | SPPG Bandung |
| `role.central` | `role.central@example.test` | `central_manager` | Global |
| `role.sppg.manager` | `role.sppg.manager@example.test` | `sppg_manager` | SPPG Bandung |
| `role.requester` | `role.requester@example.test` | `requester` | SPPG Bandung |
| `role.procurement` | `role.procurement@example.test` | `procurement` | YTB SPPG Demo |
| `role.procurement.manager` | `role.procurement.manager@example.test` | `procurement_manager` | YTB SPPG Demo |
| `role.receiver` | `role.receiver@example.test` | `receiver` | SPPG Bandung |
| `role.qc` | `role.qc@example.test` | `quality_control` | SPPG Bandung |
| `role.finance` | `role.finance@example.test` | `finance` | YTB SPPG Demo |
| `role.finance.manager` | `role.finance.manager@example.test` | `finance_manager` | YTB SPPG Demo |
| `role.auditor` | `role.auditor@example.test` | `auditor` | Global |

## Akun supplier role-isolated

| Username | Email | Role | Supplier |
|---|---|---|---|
| `vendor.ayam.admin` | `supplier.ayam.admin@example.test` | `supplier_admin` | SUP-AYAM |
| `vendor.ayam.operator` | `supplier.ayam.operator@example.test` | `supplier_operator` | SUP-AYAM |
| `vendor.tani.admin` | `supplier.tani.admin@example.test` | `supplier_admin` | SUP-TANI |
| `vendor.tani.operator` | `supplier.tani.operator@example.test` | `supplier_operator` | SUP-TANI |
| `vendor.pangan.admin` | `supplier.pangan.admin@example.test` | `supplier_admin` | SUP-PANGAN |
| `vendor.pangan.operator` | `supplier.pangan.operator@example.test` | `supplier_operator` | SUP-PANGAN |
| `vendor.pending.operator` | `supplier.pending.operator@example.test` | `supplier_operator` | SUP-PENDING |
| `vendor.suspended.admin` | `supplier.suspended.admin@example.test` | `supplier_admin` | SUP-SUSPENDED |
| `vendor.suspended.operator` | `supplier.suspended.operator@example.test` | `supplier_operator` | SUP-SUSPENDED |

## Akun skenario gabungan

Akun berikut dipakai oleh `DemoDataSeeder` untuk membangun transaksi contoh dan sengaja dapat mempunyai lebih dari satu role.

| Username | Email | Kegunaan utama |
|---|---|---|
| `admin` | `admin@example.test` | Super admin existing scenario |
| `pusat` | `pusat@example.test` | Central manager + procurement + procurement manager |
| `finance` | `finance@example.test` | Finance |
| `finance.manager` | `finance.manager@example.test` | Finance manager |
| `sppg.bandung` | `sppg.bandung@example.test` | Requester + SPPG manager + receiver + QC Bandung |
| `sppg.cimahi` | `sppg.cimahi@example.test` | Requester + SPPG manager + receiver + QC Cimahi |
| `auditor` | `auditor@example.test` | Auditor |
| `lean.operator` | `lean@example.test` | Satu orang menangani banyak proses pada organisasi lean |
| `vendor.ayam` | `supplier.ayam@example.test` | Owner/admin/operator Ayam Makmur untuk seeded scenario |
| `vendor.tani` | `supplier.tani@example.test` | Owner/admin/operator Tani Jaya untuk seeded scenario |
| `vendor.pangan` | `supplier.pangan@example.test` | Owner/admin/operator Pangan Nusantara untuk seeded scenario |
| `supplier.pending` | `supplier.pending@example.test` | Supplier yang masih menunggu verifikasi |

## Akun negatif / security test

| Username | Email | Kondisi |
|---|---|---|
| `role.inactive` | `role.inactive@example.test` | User nonaktif; mempunyai `panel_user`, tetapi tidak boleh memperoleh akses panel |

## Penggunaan untuk E2E

Gunakan akun `role.*` untuk assertions permission yang terisolasi. Gunakan akun skenario gabungan untuk menjalankan happy-path atau operational-flow yang membutuhkan beberapa tindakan dari actor yang sama. Untuk supplier, gunakan akun admin/operator role-isolated ketika menguji perbedaan hak pengelolaan profil vs aktivitas operasional.
