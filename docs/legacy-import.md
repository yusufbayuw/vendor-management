# Legacy Data Import

Import data lama diperlakukan sebagai **migration**, bukan replay workflow.

## Prinsip

- Record masuk langsung ke state historis/current state dari file.
- Tidak dibuat approval request palsu untuk keputusan yang sudah terjadi di luar sistem.
- Notifikasi workflow dinonaktifkan selama import.
- Audit Eloquent tetap berjalan.
- Setiap record mendapat `data_provenances` yang menunjuk batch, file, dan baris asal.
- Supplier existing dapat masuk sebagai `admin_managed` dan aktif secara operasional tanpa akun portal.

## Format file

Mendukung `.csv` dan `.xlsx`. Untuk XLSX, sheet pertama dibaca. Baris pertama harus berisi header.

### Supplier existing

Kolom wajib: `code, legal_name`.
Kolom opsional: `display_name, supplier_type, npwp, nib, email, phone, address, status, notes`.
Jika `status` kosong, default `active`.

### Purchase Request

Kolom wajib: `number, kitchen_code, status`.
Kolom item opsional: `product_code, unit_code, requested_qty, estimated_unit_price, estimated_total, item_description, quality_specification, item_notes`.
Kolom header opsional: `period_start, period_end, needed_from, needed_until, submitted_at, approved_at, description, notes`.
Satu PR dapat diulang dalam beberapa baris untuk item berbeda.

### Purchase Order

Kolom wajib: `number, purchase_request_number, supplier_code, kitchen_code, order_date, status`.
Kolom item opsional: `product_code, unit_code, ordered_qty, unit_price, item_subtotal, item_description, delivered_qty, accepted_qty, rejected_qty`.
Kolom header opsional: `delivery_start, delivery_end, subtotal, tax_amount, discount_amount, total_amount, approved_at, issued_at, acknowledged_at, notes`.
PR, supplier, produk dan unit yang direferensikan harus sudah ada.

### Penerimaan Barang

Kolom wajib: `number, purchase_order_number, received_at, status`.
Kolom item opsional: `product_code, unit_code, planned_qty, received_qty, accepted_qty, rejected_qty, condition, rejection_reason, item_notes`.
Kolom opsional lain: `delivery_schedule_number, supplier_representative, delivery_note_number, inspected_at, notes`.
Jika `delivery_schedule_number` kosong, sistem membuat nomor synthetic `LEGACY-{receipt_number}`.

### Invoice

Kolom wajib: `number, purchase_order_number, invoice_date, status, payable_amount`.
Kolom opsional: `supplier_invoice_number, due_date, po_amount, adjustment_amount, withholding_tax_amount, total_amount, issued_at, approved_at, notes`.

### Payment

Kolom wajib: `number, invoice_number, payment_date, amount, payment_method, status`.
`payment_method`: `bank_transfer`, `virtual_account`, `cash`, atau `other`.
Kolom opsional: `source_bank_name, destination_bank_name, destination_account_number, destination_account_holder, reference_number, verified_at, notes`.

## Catatan audit

Untuk transaksi historis, field aktor operasional yang diwajibkan schema dapat memakai user yang menjalankan migrasi. Itu bukan klaim bahwa user tersebut adalah aktor historis; provenance menyimpan `historical_actor_may_be_unknown=true` dan `workflow_replayed=false`.

Jika identitas aktor historis tersedia dan harus dipertahankan, lakukan mapping user sebelum import lanjutan.