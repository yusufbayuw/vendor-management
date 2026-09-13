# SPPG Vendor Management

Sistem procurement dan vendor management untuk dapur SPPG.

## Stack

- Laravel 13
- PHP 8.3+
- Filament 5
- Filament Shield 4.x
- Spatie Laravel Permission melalui Filament Shield

## Core workflow

`Purchase Request -> Supplier Allocation -> Purchase Order -> Delivery Schedule -> Goods Receipt -> Reconciliation -> Invoice -> Payment -> Closed`

## Prinsip arsitektur

- Filament adalah presentation/admin layer; business rule utama berada di Action/Service/domain layer.
- Role dan permission dikelola dengan Filament Shield.
- Scope data dipisahkan dari role: `global`, `organization`, `sppg_kitchen`, dan `supplier`.
- Satu user dapat memiliki banyak role dan banyak scope sehingga deployment tetap berjalan dengan SDM minimal.
- Separation of duties/self approval adalah governance policy configurable, bukan constraint permanen.
- State transition transaksi tidak boleh dilakukan dengan update status bebas.
- Seluruh aksi sensitif harus dapat diaudit.

## Role awal

- `super_admin`
- `central_manager`
- `sppg_manager`
- `requester`
- `procurement`
- `procurement_manager`
- `receiver`
- `quality_control`
- `finance`
- `finance_manager`
- `supplier_admin`
- `supplier_operator`
- `auditor`

`panel_user` dipakai sebagai baseline akses panel Filament dan bukan role bisnis.

## Implementation phases

1. Foundation: Filament, Shield, organization/SPPG scope, governance policy.
2. Master data: product categories, products, units.
3. Supplier lifecycle and verification.
4. Purchase request and approvals.
5. Supplier allocation and purchase orders.
6. Delivery scheduling and goods receipt.
7. Reconciliation/discrepancy.
8. Invoice and payment.
9. Notification, reporting, scorecard, hardening.
