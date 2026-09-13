<?php

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [
    'shield_resource' => [
        'slug' => 'administration/roles',
        'show_model_path' => false,
        'cluster' => null,
        'tabs' => [
            'pages' => false,
            'widgets' => false,
            'resources' => false,
            'custom_permissions' => true,
        ],
    ],

    'tenant_model' => null,
    'auth_provider_model' => 'App\\Models\\User',

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    'panel_user' => [
        'enabled' => true,
        'name' => 'panel_user',
    ],

    'permissions' => [
        'separator' => ':',
        'case' => 'pascal',
        'generate' => true,
        'format_custom_permission_keys' => false,
    ],

    'policies' => [
        'path' => app_path('Policies'),
        'merge' => true,
        'generate' => true,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny', 'create', 'deleteAny', 'forceDeleteAny', 'restoreAny', 'reorder',
        ],
    ],

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => ['viewAny', 'view', 'create', 'update', 'delete'],
        ],
        'exclude' => [],
    ],

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [Dashboard::class],
    ],

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [AccountWidget::class, FilamentInfoWidget::class],
    ],

    'custom_permissions' => [
        'organization.view' => 'Lihat organisasi',
        'organization.manage' => 'Kelola organisasi',
        'kitchen.view' => 'Lihat SPPG',
        'kitchen.manage' => 'Kelola SPPG',
        'master_data.view' => 'Lihat master data',
        'master_data.manage' => 'Kelola master data',
        'governance.manage' => 'Kelola tata kelola & akses',
        'supplier.view' => 'Lihat supplier',
        'supplier.verify' => 'Verifikasi supplier',
        'supplier.suspend' => 'Suspend supplier',
        'supplier.profile.manage' => 'Kelola profil supplier',
        'purchase_request.submit' => 'Ajukan purchase request',
        'purchase_request.approve' => 'Setujui purchase request',
        'purchase_request.allocate' => 'Alokasikan purchase request',
        'purchase_order.create' => 'Buat purchase order',
        'purchase_order.approve' => 'Setujui purchase order',
        'purchase_order.issue' => 'Terbitkan purchase order',
        'purchase_order.acknowledge' => 'Konfirmasi purchase order',
        'purchase_order.amendment.approve' => 'Setujui perubahan purchase order',
        'purchase_order.exception.close' => 'Tutup purchase order dengan pengecualian',
        'delivery.schedule' => 'Jadwalkan pengiriman',
        'delivery.manage' => 'Kelola pengiriman supplier',
        'goods_receipt.create' => 'Catat penerimaan barang',
        'goods_receipt.inspect' => 'Inspeksi penerimaan barang',
        'goods_receipt.reject' => 'Tolak barang',
        'invoice.submit' => 'Ajukan invoice',
        'invoice.review' => 'Review invoice',
        'invoice.approve' => 'Setujui invoice',
        'payment.create' => 'Buat pembayaran',
        'payment.verify' => 'Verifikasi pembayaran',
        'audit.view' => 'Lihat audit trail',
        'reports.view' => 'Lihat laporan',
    ],

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    'register_role_policy' => false,
];
