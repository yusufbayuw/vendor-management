@php
    $isSupplier = ($panelId ?? 'admin') === 'supplier';
    $manifest = $isSupplier ? 'manifest-supplier.webmanifest' : 'manifest-admin.webmanifest';
    $themeColor = $isSupplier ? '#10b981' : '#f59e0b';
    $appTitle = $isSupplier ? 'Portal Supplier SPPG' : 'SPPG Vendor Management';
@endphp

<link rel="manifest" href="{{ asset($manifest) }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ route('pwa.icon', ['size' => 180]) }}">
<meta name="theme-color" content="{{ $themeColor }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $appTitle }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
