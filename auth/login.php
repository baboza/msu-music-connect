<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params(86400, '/; samesite=Lax', '', false, true);
    }
    session_start();
}

// เมื่อเข้าใช้งานหน้า auth/login.php ให้ตั้งค่าเป็น PC Desktop เสมอ (ไม่เด้งไป mobile)
$_SESSION['login_target'] = 'pc';
$_SESSION['prefer_desktop'] = true;
$_SESSION['app_mode'] = 'desktop';
setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');

// หากผู้ใช้จงใจคลิกสลับไปหน้า Mobile Login ผ่านลิงก์ (?mobile=1)
if (isset($_GET['mobile'])) {
    $_SESSION['login_target'] = 'mobile';
    $_SESSION['prefer_desktop'] = false;
    $_SESSION['app_mode'] = 'mobile';
    setcookie('app_mode', 'mobile', time() + 86400 * 30, '/');
    header("Location: ../mobile/login.php?mobile=1");
    exit();
}

// หากล็อกอินอยู่แล้ว ให้เข้าสู่ระบบหน้า PC Desktop ทันที
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// --- [ตั้งค่า Google OAuth] ---
require_once __DIR__ . '/../config/constants.php';
$client_id = GOOGLE_CLIENT_ID;
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$redirect_uri = $host_url . '/music-e-office/auth/google-callback.php';

$google_auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => 'email profile',
    'access_type' => 'online',
    'prompt' => 'select_account',
    'state' => 'pc'
]);
$base_url = "/music-e-office";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>เข้าสู่ระบบ - Music MSU Connect (Enterprise ERP 2.0)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@300;400;500;600;700&family=Anuphan:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/google-sans" rel="stylesheet">
    
    <style>
        :root {
            --primary-purple: #39048f;
            --primary-purple-hover: #4a06b8;
            --primary-purple-light: #5b13df;
            --primary-purple-glow: rgba(57, 4, 143, 0.35);
            --purple-subtle: #f4efff;
            --accent-gold: #c5a028;
            --accent-gold-light: #fef9c3;
            --slate-950: #090d16;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
        }

        * {
            font-family: 'Google Sans', 'Anuphan', sans-serif;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body, button, input, select, textarea, h1, h2, h3, h4, h5, h6, a, span, p, div {
            font-family: 'Google Sans', 'Anuphan', sans-serif !important;
        }

        .fa, .fas, .far, .fal, .fad, .fab, .fa-solid, .fa-regular, .fa-brands, [class^="fa-"], [class*=" fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands" !important;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background-color: var(--slate-50);
            color: var(--slate-900);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .erp-auth-shell {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* =========================================================
           DESKTOP LEFT PANEL: ENTERPRISE BRAND & ARCHITECTURE
           ========================================================= */
        .erp-brand-panel {
            flex: 1.15;
            background: radial-gradient(circle at 15% 20%, rgba(91, 19, 223, 0.4) 0%, transparent 50%),
                        radial-gradient(circle at 85% 80%, rgba(197, 160, 40, 0.16) 0%, transparent 45%),
                        linear-gradient(145deg, #140026 0%, #090d16 65%, #0f172a 100%);
            color: #ffffff;
            padding: 3.25rem 4rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .erp-brand-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: 
                linear-gradient(to right, rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .erp-brand-top {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .erp-logo-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            padding: 8px 16px 8px 10px;
            border-radius: 12px;
        }

        .erp-logo-badge img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            background: #ffffff;
            border-radius: 8px;
            padding: 4px;
        }

        .erp-version-pill {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #fde047;
            background: rgba(250, 204, 21, 0.12);
            border: 1px solid rgba(250, 204, 21, 0.3);
            padding: 5px 12px;
            border-radius: 99px;
        }

        .erp-brand-hero {
            position: relative;
            z-index: 2;
            max-width: 580px;
            margin: 2rem 0;
        }

        .erp-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #c4b5fd;
            margin-bottom: 1rem;
        }

        .erp-hero-title {
            font-size: 2.45rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: #ffffff;
            margin-bottom: 1rem;
        }

        .erp-hero-title span {
            background: linear-gradient(90deg, #fde047 0%, #f59e0b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .erp-hero-desc {
            font-size: 0.96rem;
            color: #94a3b8;
            line-height: 1.65;
            margin-bottom: 2rem;
        }

        .erp-feature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .erp-feature-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: all 0.2s ease;
        }

        .erp-feature-card:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(255, 255, 255, 0.16);
            transform: translateY(-2px);
        }

        .erp-feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: rgba(91, 19, 223, 0.25);
            color: #ddd6fe;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .erp-feature-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 2px;
        }

        .erp-feature-sub {
            font-size: 0.74rem;
            color: #94a3b8;
            line-height: 1.4;
        }

        .erp-brand-footer {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.78rem;
            color: #64748b;
        }

        .erp-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #cbd5e1;
            font-weight: 500;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        /* =========================================================
           DESKTOP & WRAPPER: AUTH PANEL
           ========================================================= */
        .erp-auth-wrapper {
            flex: 0.85;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2.5rem;
            position: relative;
            width: 100%;
        }

        .mobile-login-hero {
            display: none;
        }

        .erp-auth-panel {
            width: 100%;
            max-width: 420px;
        }

        .erp-auth-card {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: 16px;
            padding: 2.25rem;
            box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.06);
        }

        .erp-auth-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background-color: var(--purple-subtle);
            border: 1px solid rgba(57, 4, 143, 0.12);
            color: var(--primary-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .erp-auth-heading {
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--slate-900);
            letter-spacing: -0.015em;
            margin-bottom: 0.35rem;
        }

        .erp-auth-subtitle {
            font-size: 0.86rem;
            color: var(--slate-500);
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }

        /* Google Workspace SSO Button */
        .btn-erp-google {
            width: 100%;
            background-color: var(--slate-900);
            color: #ffffff !important;
            border: 1px solid var(--slate-900);
            border-radius: 12px;
            padding: 13px 18px;
            font-size: 0.92rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
        }

        .btn-erp-google:hover {
            background-color: var(--primary-purple);
            border-color: var(--primary-purple);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(57, 4, 143, 0.25);
        }

        .btn-erp-google:active {
            transform: translateY(0);
        }

        .google-icon-pill {
            width: 28px;
            height: 28px;
            background: #ffffff;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .google-icon-pill img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .erp-domain-notice {
            margin-top: 1.25rem;
            padding: 12px 14px;
            background-color: var(--slate-50);
            border: 1px solid var(--slate-200);
            border-radius: 10px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .erp-domain-notice i {
            color: var(--primary-purple);
            margin-top: 2px;
            font-size: 0.9rem;
        }

        .erp-domain-text {
            font-size: 0.78rem;
            color: var(--slate-600);
            line-height: 1.45;
        }

        .erp-domain-badge {
            display: inline-block;
            font-weight: 700;
            color: var(--primary-purple);
            background: var(--purple-subtle);
            padding: 0 6px;
            border-radius: 4px;
            border: 1px solid rgba(57, 4, 143, 0.15);
        }

        .erp-security-meta {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--slate-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.74rem;
            color: var(--slate-400);
        }

        .erp-auth-footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.76rem;
            color: var(--slate-500);
        }

        /* =========================================================
           MOBILE & TABLET VIEWPORT (<= 991.98px)
           Redesigned Full-Bleed Enterprise Experience
           ========================================================= */
        @media (max-width: 991.98px) {
            body {
                background: #f8fafc;
            }

            body {
                overflow-x: hidden !important;
            }

            .erp-auth-shell {
                flex-direction: column;
                min-height: 100vh;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
            }

            /* Hide Desktop Left Showcase */
            .erp-brand-panel {
                display: none !important;
            }

            /* Full-width container wrapper */
            .erp-auth-wrapper {
                flex: none;
                width: 100% !important;
                max-width: 100vw !important;
                min-height: 100vh;
                padding: 0 !important;
                background: #f8fafc;
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
                align-items: stretch !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }

                        /* ==========================================
               PC MOBILE TOP HEADER: Centered Executive
               ========================================== */
            .pc-mobile-header {
                width: 100% !important;
                background: radial-gradient(circle at 15% 20%, rgba(91, 19, 223, 0.3) 0%, transparent 60%),
                            linear-gradient(155deg, #090d16 0%, #0f172a 65%, #1e293b 100%);
                border-bottom: 2px solid #c5a028;
                padding: 1.5rem 1rem 1.6rem 1rem;
                text-align: center;
                color: #ffffff;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                box-sizing: border-box !important;
            }

            .pc-mobile-logo {
                width: 52px;
                height: 52px;
                object-fit: contain;
                background: #ffffff;
                border-radius: 14px;
                padding: 5px;
                box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3), 0 0 0 3px rgba(197, 160, 40, 0.3);
                margin: 0 auto 0.75rem auto;
                display: block;
            }

            .pc-mobile-title {
                font-family: 'Outfit', sans-serif !important;
                font-size: 1.35rem;
                font-weight: 800;
                line-height: 1.2;
                color: #ffffff;
                margin-bottom: 0.2rem;
            }

            .pc-mobile-sub {
                font-size: 0.76rem;
                color: #cbd5e1;
                margin-bottom: 0.85rem;
            }

            .pc-portal-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 12px;
                background: rgba(197, 160, 40, 0.15);
                border: 1px solid rgba(197, 160, 40, 0.4);
                border-radius: 99px;
                font-size: 0.72rem;
                font-weight: 700;
                color: #fef08a;
            }

            /* ==========================================
               MOBILE DETECTION & PROMPT BANNER
               ========================================== */
            .mobile-prompt-container {
                width: 100% !important;
                max-width: 480px !important;
                margin: 0 auto !important;
                padding: 1rem 1rem 0 1rem !important;
                box-sizing: border-box !important;
            }

            .mobile-prompt-box {
                background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
                border: 1px solid #ddd6fe;
                border-radius: 16px;
                padding: 12px 14px;
                display: flex;
                flex-direction: column;
                gap: 10px;
                box-shadow: 0 4px 12px rgba(124, 58, 237, 0.08);
            }

            .prompt-icon {
                width: 36px;
                height: 36px;
                background: #7c3aed;
                color: #ffffff;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.1rem;
                flex-shrink: 0;
            }

            .prompt-info {
                flex: 1;
                text-align: left;
            }

            .prompt-head {
                font-size: 0.8rem;
                font-weight: 700;
                color: #4c1d95;
                line-height: 1.25;
            }

            .prompt-desc {
                font-size: 0.7rem;
                color: #6d28d9;
            }

            .btn-prompt-switch {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                background: #7c3aed;
                color: #ffffff !important;
                padding: 9px 14px;
                border-radius: 12px;
                font-size: 0.78rem;
                font-weight: 600;
                text-decoration: none;
                box-shadow: 0 2px 6px rgba(124, 58, 237, 0.3);
                transition: 0.15s;
                width: 100%;
            }

            .btn-prompt-switch:active {
                transform: scale(0.96);
                background: #6d28d9;
            }

            /* ==========================================
               MOBILE AUTH CONTAINER & CARD
               ========================================== */
            .erp-auth-panel {
                width: 100% !important;
                max-width: 480px !important;
                margin: 0 auto !important;
                padding: 0.85rem 1rem 2rem 1rem !important;
                position: relative;
                z-index: 10;
                box-sizing: border-box !important;
            }

            .erp-auth-card {
                border-radius: 20px !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 12px 32px -4px rgba(15, 23, 42, 0.08) !important;
                padding: 1.5rem 1.15rem !important;
                background: #ffffff !important;
                box-sizing: border-box;
            }

            .pc-version-badge {
                display: inline-flex;
                align-items: center;
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                border-radius: 99px;
                padding: 3px 10px;
                font-size: 0.72rem;
                font-weight: 700;
                margin-bottom: 0.85rem;
            }

            .erp-auth-heading {
                font-size: 1.35rem !important;
                font-weight: 700 !important;
                color: #0f172a !important;
            }

            .erp-auth-subtitle {
                font-size: 0.82rem !important;
                color: #64748b !important;
                margin-bottom: 1.35rem !important;
                line-height: 1.5 !important;
            }

            .btn-erp-google {
                padding: 12px 12px !important;
                font-size: 0.86rem !important;
                border-radius: 14px !important;
                background: #0f172a !important;
                gap: 8px !important;
                box-sizing: border-box;
            }

            /* PC Features Section on Mobile */
            .pc-features-mobile {
                margin-top: 1.25rem;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                padding: 1.25rem;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
            }

            .pc-features-heading {
                font-size: 0.78rem;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 0.85rem;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }

            .pc-feature-row {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .pcf-item {
                display: flex;
                align-items: flex-start;
                gap: 10px;
                font-size: 0.76rem;
            }

            .pcf-item i {
                font-size: 1rem;
                margin-top: 2px;
                flex-shrink: 0;
            }

            .pcf-item strong {
                display: block;
                color: #0f172a;
                font-size: 0.78rem;
            }

            .pcf-item span {
                color: #64748b;
                font-size: 0.72rem;
            }

            .text-gold { color: #c5a028 !important; }
            .text-purple { color: #7c3aed !important; }
            .text-cyan { color: #0284c7 !important; }
            .text-amber { color: #d97706 !important; }

            .erp-auth-footer {
                margin-top: 1.5rem !important;
                font-size: 0.74rem !important;
                color: var(--slate-600) !important;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="erp-auth-shell">
    
    <!-- =========================================================
         LEFT PANEL: ENTERPRISE BRANDING & CAPABILITIES (Desktop Only)
         ========================================================= -->
    <aside class="erp-brand-panel">
        <div class="erp-brand-top">
            <div class="erp-logo-badge">
                <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="MSU Music Logo">
                <div>
                    <div class="fw-bold text-white" style="font-size: 0.92rem; line-height: 1.15;">Music MSU Connect</div>
                    <div style="font-size: 0.7rem; color: #cbd5e1;">College of Music, Mahasarakham University</div>
                </div>
            </div>
            <span class="erp-version-pill">Enterprise ERP 2.0</span>
        </div>

        <div class="erp-brand-hero">
            <div class="erp-eyebrow">
                <i class="fa-solid fa-layer-group"></i>
                <span>DIGITAL WORKSPACE & EXECUTIVE COMMAND CENTER</span>
            </div>
            <h1 class="erp-hero-title">
                ระบบสารสนเทศเพื่อการบริหารจัดการ<span>องค์กรอัจฉริยะ</span>
            </h1>
            <p class="erp-hero-desc">
                แพลตฟอร์มปฏิบัติการกลางสำหรับคณาจารย์และบุคลากร วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม เชื่อมโยงระบบบริหารงานบุคคล การเงิน โครงการ อาคารสถานที่ และแดชบอร์ดผู้บริหารแบบเรียลไทม์
            </p>

            <div class="erp-feature-grid">
                <div class="erp-feature-card">
                    <div class="erp-feature-icon"><i class="fa-solid fa-file-signature"></i></div>
                    <div>
                        <div class="erp-feature-title">E-Workflow & Approvals</div>
                        <div class="erp-feature-sub">พิจารณาอนุมัติใบลา ไปราชการ และคำร้องออนไลน์ครบวงจร</div>
                    </div>
                </div>
                <div class="erp-feature-card">
                    <div class="erp-feature-icon"><i class="fa-solid fa-chart-pie"></i></div>
                    <div>
                        <div class="erp-feature-title">Executive Telemetry</div>
                        <div class="erp-feature-sub">ติดตามงบประมาณ โครงการ และดัชนีองค์กรแบบเรียลไทม์</div>
                    </div>
                </div>
                <div class="erp-feature-card">
                    <div class="erp-feature-icon"><i class="fa-solid fa-music"></i></div>
                    <div>
                        <div class="erp-feature-title">Smart Facility & Rooms</div>
                        <div class="erp-feature-sub">บริหารจัดการห้องซ้อมดนตรี ห้องประชุม และแจ้งซ่อมบำรุง</div>
                    </div>
                </div>
                <div class="erp-feature-card">
                    <div class="erp-feature-icon"><i class="fa-solid fa-bolt"></i></div>
                    <div>
                        <div class="erp-feature-title">Command Palette (Ctrl+K)</div>
                        <div class="erp-feature-sub">เข้าถึงทุกโมดูลและคำสั่งด่วนด้วยคีย์บอร์ดภายในเสี้ยววินาที</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="erp-brand-footer">
            <div class="erp-status-indicator">
                <span class="status-dot"></span>
                <span>All ERP Services Operational</span>
            </div>
            <div>&copy; <?php echo date('Y'); ?> วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
        </div>
    </aside>

    <!-- =========================================================
         RIGHT PANEL: SSO AUTHENTICATION (Desktop & Mobile)
         ========================================================= -->
    <div class="erp-auth-wrapper">
        
        <!-- =========================================================
             MOBILE HERO BANNER: Shown only on Mobile/Tablet
             ========================================================= -->
        <!-- PC Desktop Header for Mobile View -->
        <header class="pc-mobile-header d-lg-none">
            <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="MSU Music Logo" class="pc-mobile-logo">
            <h1 class="pc-mobile-title">Music MSU Connect</h1>
            <div class="pc-mobile-sub">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
            <div class="pc-portal-badge">
                <i class="fa-solid fa-laptop-code text-gold"></i>
                <span>ระบบ e-Office • เวอร์ชัน PC Desktop</span>
            </div>
        </header>

        <!-- Mobile Device Detection Prompt Banner -->
        <div class="mobile-prompt-container d-lg-none">
            <div class="mobile-prompt-box">
                <div class="d-flex align-items-center gap-2">
                    <div class="prompt-icon">
                        <i class="fa-solid fa-mobile-screen-button"></i>
                    </div>
                    <div class="prompt-info">
                        <div class="prompt-head">คุณกำลังเปิดหน้า PC บนสมาร์ทโฟน</div>
                        <div class="prompt-desc">ต้องการใช้งานแบบแอปมือถือที่สะดวกรวดเร็วกว่า?</div>
                    </div>
                </div>
                <a href="../mobile/login.php?mobile=1" class="btn-prompt-switch">
                    <i class="fa-solid fa-mobile-screen me-1"></i> สลับไปใช้งานเวอร์ชัน Mobile App <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>

        <!-- =========================================================
             AUTH CARD CONTAINER
             ========================================================= -->
        <main class="erp-auth-panel">
            <div class="erp-auth-card">
                <div class="erp-auth-icon-box">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>

                <div class="mb-2">
                    <span class="pc-version-badge">
                        <i class="fa-solid fa-desktop text-purple me-1"></i> เวอร์ชันเต็ม PC Desktop ERP
                    </span>
                </div>
                <h2 class="erp-auth-heading">เข้าสู่ระบบปฏิบัติการ</h2>
                <p class="erp-auth-subtitle">
                    ยืนยันตัวตนผ่านระบบ Single Sign-On (SSO) เพื่อเข้าถึงพื้นที่ทำงานคอมพิวเตอร์
                </p>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger border-0 d-flex align-items-start gap-2 mb-4" style="border-radius: 10px; font-size: 0.82rem; background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca !important;">
                        <i class="fa-solid fa-circle-exclamation mt-1"></i>
                        <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    </div>
                <?php endif; ?>

                <a href="<?php echo $google_auth_url; ?>" class="btn-erp-google">
                    <span class="google-icon-pill">
                        <img src="<?php echo $base_url; ?>/uploads/gglogo.png" alt="Google">
                    </span>
                    <span class="flex-grow-1 text-center">เข้าสู่ระบบด้วย Google มหาวิทยาลัย</span>
                    <i class="fa-solid fa-arrow-right d-none d-sm-inline" style="font-size: 0.8rem; opacity: 0.8;"></i>
                </a>

                <div class="erp-domain-notice">
                    <i class="fa-solid fa-circle-info"></i>
                    <div class="erp-domain-text">
                        กรุณาใช้อีเมลบุคลากรของมหาวิทยาลัยมหาสารคาม <span class="erp-domain-badge">@msu.ac.th</span> ในการเข้าสู่ระบบเท่านั้น
                    </div>
                </div>

                <div class="erp-security-meta">
                    <span><i class="fa-solid fa-lock me-1"></i>OAuth 2.0 Encrypted</span>
                    <span>ERP Build 2026.2</span>
                </div>
            </div>

            <!-- PC Features Section on Mobile Screen -->
            <div class="pc-features-mobile d-lg-none">
                <div class="pc-features-heading">
                    <i class="fa-solid fa-layer-group me-1 text-gold"></i> ขอบเขตระบบงานเวอร์ชันเต็ม (PC ERP)
                </div>
                <div class="pc-feature-row">
                    <div class="pcf-item">
                        <i class="fa-solid fa-file-invoice text-purple"></i>
                        <div>
                            <strong>ระบบสารบรรณ & อนุมัติเอกสาร</strong>
                            <span>จัดการหนังสือเวียน แนบไฟล์ พิมพ์เอกสารราชการ</span>
                        </div>
                    </div>
                    <div class="pcf-item">
                        <i class="fa-solid fa-chart-pie text-cyan"></i>
                        <div>
                            <strong>แดชบอร์ดงบประมาณ & โครงการ</strong>
                            <span>ติดตามงบประมาณ รายงานผล และสถิติเชิงลึก</span>
                        </div>
                    </div>
                    <div class="pcf-item">
                        <i class="fa-solid fa-building-user text-amber"></i>
                        <div>
                            <strong>บริหารห้องประชุมและพัสดุครุภัณฑ์</strong>
                            <span>ระบบจัดการห้องประชุม ยืมคืนพัสดุ และครุภัณฑ์</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="erp-auth-footer">
                <div>พบปัญหาการเข้าใช้งาน กรุณาติดต่อ <strong>งานเทคโนโลยีสารสนเทศ</strong></div>
                <div class="mt-1 text-muted" style="font-size: 0.7rem;">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
            </div>
        </main>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
