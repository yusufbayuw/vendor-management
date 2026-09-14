<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#047857">
    <meta name="description" content="Portal kemitraan supplier SPPG untuk registrasi, verifikasi, Purchase Order, pengiriman, invoice, dan pembayaran.">

    <title>Portal Kemitraan Supplier SPPG</title>

    <link rel="icon" type="image/png" href="{{ url('/pwa/icon/192.png') }}">

    <style>
        :root {
            color-scheme: light;
            --ink: #10231d;
            --muted: #60716a;
            --surface: #ffffff;
            --surface-soft: #f5f8f6;
            --line: #dfe8e3;
            --emerald: #047857;
            --emerald-dark: #065f46;
            --emerald-soft: #dff4e9;
            --amber: #c77908;
            --shadow: 0 24px 70px rgba(16, 35, 29, .12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-width: 320px;
            background: #f8faf9;
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        a {
            -webkit-tap-highlight-color: transparent;
        }

        .shell {
            width: min(1180px, calc(100% - 40px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 30;
            border-bottom: 1px solid rgba(223, 232, 227, .84);
            background: rgba(248, 250, 249, .88);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .nav {
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 14px;
            color: #fff;
            background: linear-gradient(145deg, #059669, #047857);
            box-shadow: 0 10px 25px rgba(4, 120, 87, .22);
        }

        .brand-mark svg {
            width: 24px;
            height: 24px;
        }

        .brand-copy {
            min-width: 0;
        }

        .brand-copy strong,
        .brand-copy span {
            display: block;
        }

        .brand-copy strong {
            font-size: 14px;
            line-height: 1.25;
            letter-spacing: -.01em;
        }

        .brand-copy span {
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 24px;
            color: #42554d;
            font-size: 14px;
            font-weight: 650;
        }

        .nav-links a:hover {
            color: var(--emerald);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .button {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 10px 17px;
            border: 1px solid transparent;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 750;
            line-height: 1;
            transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button-primary {
            color: #fff;
            background: var(--emerald);
            box-shadow: 0 12px 25px rgba(4, 120, 87, .18);
        }

        .button-primary:hover {
            background: var(--emerald-dark);
            box-shadow: 0 15px 32px rgba(4, 120, 87, .24);
        }

        .button-secondary {
            border-color: #ccd9d2;
            background: rgba(255, 255, 255, .72);
            color: #294039;
        }

        .button-secondary:hover {
            border-color: #9fc2b1;
            background: #fff;
        }

        .hero-wrap {
            position: relative;
            overflow: hidden;
            border-bottom: 1px solid var(--line);
            background:
                radial-gradient(circle at 85% 18%, rgba(16, 185, 129, .13), transparent 27%),
                radial-gradient(circle at 8% 70%, rgba(245, 158, 11, .09), transparent 24%),
                linear-gradient(180deg, #fbfdfc 0%, #f4f8f6 100%);
        }

        .hero-wrap::before,
        .hero-wrap::after {
            content: "";
            position: absolute;
            pointer-events: none;
        }

        .hero-wrap::before {
            width: 420px;
            height: 420px;
            top: 36px;
            right: -180px;
            border: 1px solid rgba(4, 120, 87, .09);
            border-radius: 90px;
            transform: rotate(28deg);
        }

        .hero-wrap::after {
            width: 190px;
            height: 190px;
            left: -86px;
            bottom: 40px;
            border: 1px solid rgba(199, 121, 8, .12);
            border-radius: 48px;
            transform: rotate(42deg);
        }

        .hero {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
            align-items: center;
            gap: 72px;
            min-height: 650px;
            padding: 84px 0 94px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 7px 11px;
            border: 1px solid #cde5d9;
            border-radius: 999px;
            color: var(--emerald-dark);
            background: rgba(239, 250, 244, .9);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .eyebrow-dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #10b981;
            box-shadow: 0 0 0 5px rgba(16, 185, 129, .11);
        }

        .hero h1 {
            max-width: 760px;
            margin: 21px 0 20px;
            font-size: clamp(40px, 5vw, 66px);
            line-height: 1.03;
            letter-spacing: -.045em;
        }

        .hero h1 span {
            color: var(--emerald);
        }

        .hero-lead {
            max-width: 690px;
            margin: 0;
            color: #53665e;
            font-size: clamp(17px, 1.6vw, 20px);
            line-height: 1.75;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 32px;
        }

        .hero-actions .button {
            min-height: 51px;
            padding-inline: 22px;
            border-radius: 14px;
            font-size: 15px;
        }

        .micro-trust {
            display: flex;
            flex-wrap: wrap;
            gap: 18px 24px;
            margin-top: 28px;
            color: #63756d;
            font-size: 13px;
            font-weight: 650;
        }

        .micro-trust span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .micro-trust svg {
            width: 17px;
            height: 17px;
            color: var(--emerald);
        }

        .journey-card {
            position: relative;
            border: 1px solid rgba(204, 222, 213, .9);
            border-radius: 28px;
            background: rgba(255, 255, 255, .82);
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            overflow: hidden;
        }

        .journey-card::before {
            content: "";
            display: block;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #047857 60%, #f59e0b);
        }

        .journey-head {
            padding: 28px 28px 20px;
            border-bottom: 1px solid #e7eee9;
        }

        .journey-head small {
            display: block;
            color: var(--emerald);
            font-size: 11px;
            font-weight: 850;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .journey-head h2 {
            margin: 7px 0 0;
            font-size: 23px;
            line-height: 1.25;
            letter-spacing: -.02em;
        }

        .journey-list {
            list-style: none;
            margin: 0;
            padding: 8px 28px 28px;
        }

        .journey-list li {
            position: relative;
            display: grid;
            grid-template-columns: 36px minmax(0, 1fr);
            gap: 14px;
            padding-top: 19px;
        }

        .journey-list li:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 52px;
            left: 17px;
            width: 1px;
            height: calc(100% - 18px);
            background: #dce9e2;
        }

        .journey-number {
            position: relative;
            z-index: 1;
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border: 1px solid #cde5d9;
            border-radius: 12px;
            color: var(--emerald-dark);
            background: #effaf4;
            font-size: 12px;
            font-weight: 900;
        }

        .journey-copy strong,
        .journey-copy span {
            display: block;
        }

        .journey-copy strong {
            margin-top: 1px;
            font-size: 14px;
        }

        .journey-copy span {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .section {
            padding: 92px 0;
        }

        .section-muted {
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .section-heading {
            max-width: 720px;
            margin-bottom: 42px;
        }

        .section-kicker {
            color: var(--emerald);
            font-size: 12px;
            font-weight: 850;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .section-heading h2 {
            margin: 8px 0 13px;
            font-size: clamp(30px, 3.5vw, 43px);
            line-height: 1.14;
            letter-spacing: -.035em;
        }

        .section-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.75;
        }

        .process-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
        }

        .process-card {
            position: relative;
            min-height: 210px;
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--surface);
        }

        .process-card .step {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            color: var(--emerald-dark);
            background: var(--emerald-soft);
            font-size: 12px;
            font-weight: 900;
        }

        .process-card h3 {
            margin: 23px 0 8px;
            font-size: 16px;
            line-height: 1.35;
        }

        .process-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.65;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .feature-card {
            padding: 28px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--surface);
            box-shadow: 0 10px 30px rgba(16, 35, 29, .035);
        }

        .feature-icon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            color: var(--emerald);
            background: #edf8f2;
        }

        .feature-icon svg {
            width: 23px;
            height: 23px;
        }

        .feature-card h3 {
            margin: 20px 0 8px;
            font-size: 18px;
            letter-spacing: -.015em;
        }

        .feature-card p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        .prep-layout {
            display: grid;
            grid-template-columns: .84fr 1.16fr;
            gap: 70px;
            align-items: start;
        }

        .prep-copy h2 {
            margin: 8px 0 15px;
            font-size: clamp(30px, 3.4vw, 43px);
            line-height: 1.15;
            letter-spacing: -.035em;
        }

        .prep-copy p {
            margin: 0;
            color: var(--muted);
        }

        .prep-note {
            margin-top: 24px;
            padding: 16px 18px;
            border: 1px solid #f0dfbf;
            border-radius: 16px;
            color: #76551d;
            background: #fff9ec;
            font-size: 13px;
            line-height: 1.6;
        }

        .prep-list {
            display: grid;
            gap: 12px;
        }

        .prep-item {
            display: grid;
            grid-template-columns: 46px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
            padding: 20px;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: #fff;
        }

        .prep-item-icon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            color: var(--emerald);
            background: #eff9f4;
        }

        .prep-item-icon svg {
            width: 22px;
            height: 22px;
        }

        .prep-item strong {
            display: block;
            font-size: 15px;
        }

        .prep-item span {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .trust-panel {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            padding: 34px;
            border: 1px solid #cfe2d8;
            border-radius: 26px;
            background:
                radial-gradient(circle at 90% 0%, rgba(16, 185, 129, .11), transparent 38%),
                #f7fcf9;
        }

        .trust-panel h2 {
            margin: 7px 0 13px;
            font-size: clamp(28px, 3vw, 39px);
            line-height: 1.15;
            letter-spacing: -.03em;
        }

        .trust-panel p {
            margin: 0;
            color: var(--muted);
        }

        .trust-list {
            display: grid;
            gap: 12px;
        }

        .trust-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 16px;
            border: 1px solid rgba(204, 224, 214, .9);
            border-radius: 14px;
            background: rgba(255, 255, 255, .78);
            color: #41564d;
            font-size: 13px;
            font-weight: 650;
        }

        .trust-check {
            width: 23px;
            height: 23px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 999px;
            color: #fff;
            background: var(--emerald);
        }

        .trust-check svg {
            width: 13px;
            height: 13px;
        }

        .faq-list {
            display: grid;
            gap: 11px;
            max-width: 880px;
        }

        details {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
        }

        summary {
            position: relative;
            padding: 20px 52px 20px 20px;
            cursor: pointer;
            list-style: none;
            font-size: 14px;
            font-weight: 760;
        }

        summary::-webkit-details-marker {
            display: none;
        }

        summary::after {
            content: "+";
            position: absolute;
            top: 50%;
            right: 20px;
            width: 26px;
            height: 26px;
            display: grid;
            place-items: center;
            transform: translateY(-50%);
            border-radius: 8px;
            color: var(--emerald);
            background: #eff8f3;
            font-size: 18px;
            font-weight: 500;
        }

        details[open] summary::after {
            content: "−";
        }

        details p {
            margin: -2px 20px 20px;
            padding-top: 15px;
            border-top: 1px solid #edf1ee;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.75;
        }

        .final-cta-wrap {
            padding: 0 0 92px;
        }

        .final-cta {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 32px;
            padding: 42px;
            border-radius: 28px;
            color: #fff;
            background: linear-gradient(135deg, #065f46, #047857 58%, #0a8d67);
            box-shadow: 0 25px 60px rgba(4, 120, 87, .22);
        }

        .final-cta::after {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            right: -70px;
            bottom: -140px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 70px;
            transform: rotate(32deg);
        }

        .final-cta-copy {
            position: relative;
            z-index: 1;
        }

        .final-cta h2 {
            margin: 0 0 9px;
            font-size: clamp(27px, 3vw, 39px);
            line-height: 1.15;
            letter-spacing: -.03em;
        }

        .final-cta p {
            max-width: 650px;
            margin: 0;
            color: rgba(255, 255, 255, .76);
        }

        .final-cta-actions {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 10px;
        }

        .final-cta .button-primary {
            color: var(--emerald-dark);
            background: #fff;
            box-shadow: none;
        }

        .final-cta .button-secondary {
            border-color: rgba(255, 255, 255, .28);
            color: #fff;
            background: rgba(255, 255, 255, .08);
        }

        .site-footer {
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .footer-row {
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .footer-copy {
            color: #74827c;
            font-size: 12px;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            color: #53665e;
            font-size: 12px;
            font-weight: 700;
        }

        .footer-links a:hover {
            color: var(--emerald);
        }

        @media (max-width: 1040px) {
            .nav-links {
                display: none;
            }

            .hero {
                grid-template-columns: 1fr 400px;
                gap: 44px;
            }

            .process-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 860px) {
            .hero {
                grid-template-columns: 1fr;
                min-height: auto;
                padding-block: 70px;
            }

            .hero-copy {
                max-width: 720px;
            }

            .journey-card {
                max-width: 620px;
            }

            .feature-grid,
            .prep-layout,
            .trust-panel {
                grid-template-columns: 1fr;
            }

            .prep-layout {
                gap: 38px;
            }

            .final-cta {
                grid-template-columns: 1fr;
            }

            .final-cta-actions {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 660px) {
            .shell {
                width: min(100% - 28px, 1180px);
            }

            .site-header {
                position: static;
            }

            .nav {
                min-height: 68px;
            }

            .brand-copy span {
                display: none;
            }

            .nav-actions .button-secondary {
                display: none;
            }

            .nav-actions .button {
                min-height: 40px;
                padding: 9px 13px;
                font-size: 12px;
            }

            .hero {
                padding-block: 56px 68px;
            }

            .hero h1 {
                font-size: clamp(38px, 12vw, 52px);
            }

            .hero-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .hero-actions .button {
                width: 100%;
            }

            .journey-head,
            .journey-list {
                padding-left: 20px;
                padding-right: 20px;
            }

            .section {
                padding: 68px 0;
            }

            .process-grid,
            .feature-grid {
                grid-template-columns: 1fr;
            }

            .process-card {
                min-height: 0;
            }

            .trust-panel,
            .final-cta {
                padding: 26px 22px;
                border-radius: 22px;
            }

            .final-cta-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .footer-row {
                align-items: flex-start;
                flex-direction: column;
                padding: 26px 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                transition-duration: .01ms !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="shell nav">
        <a class="brand" href="{{ url('/') }}" aria-label="Portal Kemitraan Supplier SPPG">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M5 20V10.5L12 5l7 5.5V20" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8.5 20v-5.5h7V20M8.5 10.5h7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="brand-copy">
                <strong>Portal Kemitraan Supplier SPPG</strong>
                <span>{{ config('app.name', 'SPPG Vendor Management') }}</span>
            </span>
        </a>

        <nav class="nav-links" aria-label="Navigasi utama">
            <a href="#alur">Alur Kemitraan</a>
            <a href="#portal">Portal Supplier</a>
            <a href="#persiapan">Persiapan</a>
            <a href="#faq">FAQ</a>
        </nav>

        <div class="nav-actions">
            <a class="button button-secondary" href="{{ url('/supplier/login') }}">Masuk Supplier</a>
            <a class="button button-primary" href="{{ url('/supplier/register') }}">Daftar Supplier</a>
        </div>
    </div>
</header>

<main>
    <section class="hero-wrap">
        <div class="shell hero">
            <div class="hero-copy">
                <div class="eyebrow"><span class="eyebrow-dot"></span>Kemitraan supplier terkelola</div>
                <h1>Satu portal untuk <span>bermitra dengan SPPG</span> dari awal sampai pembayaran.</h1>
                <p class="hero-lead">
                    Daftarkan usaha Anda, lengkapi proses verifikasi, terima Purchase Order, kelola pengiriman, ajukan invoice, dan pantau proses transaksi dalam alur yang terdokumentasi.
                </p>

                <div class="hero-actions">
                    <a class="button button-primary" href="{{ url('/supplier/register') }}">
                        Mulai Daftar Supplier
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a class="button button-secondary" href="{{ url('/supplier/login') }}">Saya sudah punya akun</a>
                </div>

                <div class="micro-trust" aria-label="Keunggulan portal">
                    <span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Status proses dapat dipantau
                    </span>
                    <span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Dokumen dilindungi akses
                    </span>
                    <span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Transaksi terdokumentasi
                    </span>
                </div>
            </div>

            <aside class="journey-card" aria-label="Ringkasan perjalanan supplier">
                <div class="journey-head">
                    <small>Perjalanan supplier</small>
                    <h2>Dari pendaftaran hingga transaksi berjalan</h2>
                </div>
                <ol class="journey-list">
                    <li>
                        <span class="journey-number">01</span>
                        <span class="journey-copy"><strong>Registrasi</strong><span>Buat akun dan data awal supplier.</span></span>
                    </li>
                    <li>
                        <span class="journey-number">02</span>
                        <span class="journey-copy"><strong>Lengkapi profil</strong><span>Tambahkan PIC, rekening, dokumen, dan produk.</span></span>
                    </li>
                    <li>
                        <span class="journey-number">03</span>
                        <span class="journey-copy"><strong>Verifikasi</strong><span>Tim melakukan review dan dapat meminta perbaikan.</span></span>
                    </li>
                    <li>
                        <span class="journey-number">04</span>
                        <span class="journey-copy"><strong>Mulai bermitra</strong><span>Supplier aktif dapat menerima dan mengonfirmasi PO.</span></span>
                    </li>
                    <li>
                        <span class="journey-number">05</span>
                        <span class="journey-copy"><strong>Pengiriman & pembayaran</strong><span>Kelola delivery, invoice, dan status pembayaran.</span></span>
                    </li>
                </ol>
            </aside>
        </div>
    </section>

    <section class="section" id="alur">
        <div class="shell">
            <div class="section-heading">
                <span class="section-kicker">Alur kemitraan</span>
                <h2>Jelas sejak sebelum mendaftar.</h2>
                <p>Registrasi tidak otomatis menjadikan supplier aktif. Data dan dokumen akan melalui proses review agar hubungan kerja sama dimulai dari informasi yang lengkap dan terverifikasi.</p>
            </div>

            <div class="process-grid">
                <article class="process-card">
                    <span class="step">01</span>
                    <h3>Daftar akun</h3>
                    <p>Buat akun supplier agar proses onboarding dan komunikasi tercatat di portal.</p>
                </article>
                <article class="process-card">
                    <span class="step">02</span>
                    <h3>Lengkapi data</h3>
                    <p>Isi profil usaha, PIC, rekening, dokumen pendukung, dan komoditas yang ditawarkan.</p>
                </article>
                <article class="process-card">
                    <span class="step">03</span>
                    <h3>Review & perbaikan</h3>
                    <p>Tim memverifikasi data. Jika ada yang perlu dilengkapi, supplier dapat memperbaikinya melalui portal.</p>
                </article>
                <article class="process-card">
                    <span class="step">04</span>
                    <h3>Aktivasi</h3>
                    <p>Supplier yang memenuhi proses verifikasi dapat diaktifkan sebagai mitra pada proses procurement.</p>
                </article>
                <article class="process-card">
                    <span class="step">05</span>
                    <h3>Transaksi berjalan</h3>
                    <p>Kelola PO, jadwal pengiriman, dokumen penerimaan, invoice, dan pembayaran dari satu portal.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section section-muted" id="portal">
        <div class="shell">
            <div class="section-heading">
                <span class="section-kicker">Portal supplier</span>
                <h2>Bukan sekadar tempat mengunggah dokumen.</h2>
                <p>Portal dirancang mengikuti pekerjaan supplier sehari-hari setelah menjadi mitra, sehingga status transaksi tidak bergantung pada chat atau pencatatan terpisah.</p>
            </div>

            <div class="feature-grid">
                <article class="feature-card">
                    <div class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h10v4H7zM5 5H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1M7 12h10M7 16h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>Purchase Order yang jelas</h3>
                    <p>Lihat PO yang diterbitkan, konfirmasi penerimaan, dan gunakan satu sumber informasi untuk detail transaksi.</p>
                </article>

                <article class="feature-card">
                    <div class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h11v10H3zM14 9h4l3 3v4h-7zM7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>Pengiriman & penerimaan</h3>
                    <p>Kelola jadwal pengiriman dan ikuti proses penerimaan serta quality control secara lebih tertib.</p>
                </article>

                <article class="feature-card">
                    <div class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 3h9l3 3v15H6zM14 3v4h4M9 12h6M9 16h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>Invoice & pembayaran</h3>
                    <p>Ajukan invoice terkait PO dan pantau proses pembayaran tanpa kehilangan jejak dokumen transaksi.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section" id="persiapan">
        <div class="shell prep-layout">
            <div class="prep-copy">
                <span class="section-kicker">Sebelum mendaftar</span>
                <h2>Siapkan informasi utama usaha Anda.</h2>
                <p>Data yang lengkap membantu proses review berjalan lebih efisien. Persyaratan spesifik dapat menyesuaikan jenis supplier dan kebijakan organisasi.</p>
                <div class="prep-note">
                    Pendaftaran adalah awal proses verifikasi, bukan jaminan penetapan sebagai supplier aktif atau pemberian Purchase Order.
                </div>
            </div>

            <div class="prep-list">
                <div class="prep-item">
                    <span class="prep-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 21V5l8-3 8 3v16M8 8h.01M12 8h.01M16 8h.01M8 12h.01M12 12h.01M16 12h.01M9 21v-5h6v5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span><strong>Identitas dan profil usaha</strong><span>Informasi dasar supplier yang dapat diverifikasi dan digunakan dalam proses procurement.</span></span>
                </div>
                <div class="prep-item">
                    <span class="prep-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span><strong>PIC yang dapat dihubungi</strong><span>Kontak penanggung jawab yang aktif untuk verifikasi dan komunikasi transaksi.</span></span>
                </div>
                <div class="prep-item">
                    <span class="prep-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 10h18M5 10V7l7-4 7 4v3M5 10v9m4-9v9m6-9v9m4-9v9M3 21h18" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span><strong>Rekening dan dokumen pendukung</strong><span>Data pembayaran dan dokumen administratif yang relevan dengan proses verifikasi.</span></span>
                </div>
                <div class="prep-item">
                    <span class="prep-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h18M5 7l1 14h12l1-14M8 7l1-4h6l1 4M9 11v6m6-6v6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span><strong>Produk atau komoditas</strong><span>Informasi barang yang dapat disediakan beserta satuan dan informasi penawaran terkait.</span></span>
                </div>
            </div>
        </div>
    </section>

    <section class="section section-muted">
        <div class="shell">
            <div class="trust-panel">
                <div>
                    <span class="section-kicker">Keamanan & transparansi</span>
                    <h2>Dokumen bisnis tidak diperlakukan seperti file publik.</h2>
                    <p>Akses portal, file, transaksi, dan notifikasi mengikuti otorisasi user serta hubungan supplier yang relevan.</p>
                </div>
                <div class="trust-list">
                    <div class="trust-item"><span class="trust-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 12 4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span>Dokumen supplier dan transaksi dikirim melalui akses terproteksi, bukan direct public storage URL.</span></div>
                    <div class="trust-item"><span class="trust-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 12 4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span>Aktivitas penting dan perubahan proses dapat dicatat untuk kebutuhan penelusuran dan audit.</span></div>
                    <div class="trust-item"><span class="trust-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 12 4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span>Notifikasi operasional diarahkan ke user yang sesuai dengan permission dan scope transaksi.</span></div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="faq">
        <div class="shell">
            <div class="section-heading">
                <span class="section-kicker">Pertanyaan umum</span>
                <h2>Sebelum mulai registrasi.</h2>
            </div>

            <div class="faq-list">
                <details>
                    <summary>Apakah setelah mendaftar supplier langsung aktif?</summary>
                    <p>Tidak. Registrasi membuat akun dan memulai proses onboarding. Data supplier tetap perlu dilengkapi dan melalui proses review atau verifikasi sebelum dapat diaktifkan sebagai mitra.</p>
                </details>
                <details>
                    <summary>Bagaimana jika dokumen atau data saya perlu diperbaiki?</summary>
                    <p>Jika hasil review membutuhkan revisi, supplier dapat memperbaiki informasi melalui portal sesuai kebutuhan verifikasi yang disampaikan.</p>
                </details>
                <details>
                    <summary>Apakah portal hanya digunakan saat pendaftaran?</summary>
                    <p>Tidak. Setelah supplier aktif, portal digunakan untuk kebutuhan transaksi seperti Purchase Order, pengiriman, invoice, notifikasi, dan proses lain yang relevan dengan kerja sama.</p>
                </details>
                <details>
                    <summary>Bagaimana keamanan file yang saya unggah?</summary>
                    <p>File sensitif tidak dipublikasikan sebagai URL storage terbuka. Akses dilakukan melalui route terproteksi yang memeriksa autentikasi dan otorisasi user.</p>
                </details>
                <details>
                    <summary>Saya sudah memiliki akun. Ke mana saya harus masuk?</summary>
                    <p>Gunakan tombol Masuk Supplier di halaman ini untuk membuka portal supplier. Staf internal menggunakan panel internal yang terpisah.</p>
                </details>
            </div>
        </div>
    </section>

    <div class="final-cta-wrap">
        <div class="shell">
            <section class="final-cta" aria-label="Mulai kemitraan supplier">
                <div class="final-cta-copy">
                    <h2>Siap memulai proses kemitraan?</h2>
                    <p>Buat akun supplier untuk memulai onboarding, atau masuk jika usaha Anda sudah terdaftar di portal.</p>
                </div>
                <div class="final-cta-actions">
                    <a class="button button-primary" href="{{ url('/supplier/register') }}">Daftar Supplier</a>
                    <a class="button button-secondary" href="{{ url('/supplier/login') }}">Masuk Portal</a>
                </div>
            </section>
        </div>
    </div>
</main>

<footer class="site-footer">
    <div class="shell footer-row">
        <div class="footer-copy">&copy; {{ now()->year }} {{ config('app.name', 'SPPG Vendor Management') }}. Portal procurement dan kemitraan supplier.</div>
        <div class="footer-links">
            <a href="{{ url('/supplier/login') }}">Portal Supplier</a>
            <a href="{{ url('/supplier/register') }}">Registrasi Supplier</a>
            <a href="{{ url('/admin/login') }}">Akses Internal</a>
        </div>
    </div>
</footer>
</body>
</html>
