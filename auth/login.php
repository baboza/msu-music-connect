<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ตรวจจับว่าเป็นอุปกรณ์พกพาจริงหรือไม่ (ไม่ใช้ Cookie เก่ามาตัดสินแทน PC)
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_real_mobile = (bool)preg_match('/(android|iphone|ipod|blackberry|windows phone|mobile)/i', $user_agent);

if (isset($_GET['pc'])) {
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');
} elseif (isset($_GET['mobile'])) {
    $_SESSION['prefer_desktop'] = false;
    $_SESSION['app_mode'] = 'mobile';
    setcookie('app_mode', 'mobile', time() + 86400 * 30, '/');
    header("Location: ../mobile/login.php?mobile=1");
    exit();
} elseif (!$is_real_mobile) {
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');
}

// หากเข้าจากมือถือจริงๆ และไม่ได้เลือก PC ให้สลับไปหน้า Mobile Login อัตโนมัติ
if ($is_real_mobile && !isset($_GET['pc']) && empty($_SESSION['prefer_desktop'])) {
    header("Location: ../mobile/login.php");
    exit();
}

// หากล็อกอินอยู่แล้ว ให้ไปหน้าแรกตามอุปกรณ์
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($is_real_mobile && empty($_SESSION['prefer_desktop']) ? "../mobile/index.php" : "../index.php"));
    exit();
}

// --- [ตั้งค่า Google OAuth] ---
require_once '../config/constants.php';
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
    'prompt' => 'select_account'
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

            .erp-auth-shell {
                flex-direction: column;
                min-height: 100vh;
                width: 100%;
            }

            /* Hide Desktop Left Showcase */
            .erp-brand-panel {
                display: none !important;
            }

            /* Full-width container wrapper */
            .erp-auth-wrapper {
                flex: none;
                width: 100%;
                min-height: 100vh;
                padding: 0;
                background: #f8fafc;
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
                align-items: center;
            }

            /* ==========================================
               MOBILE HERO HEADER: Deep #39048f Gradient
               ========================================== */
            .mobile-login-hero {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                width: 100%;
                background: radial-gradient(circle at 85% 15%, rgba(91, 19, 223, 0.5) 0%, transparent 60%),
                            radial-gradient(circle at 15% 85%, rgba(197, 160, 40, 0.16) 0%, transparent 45%),
                            linear-gradient(155deg, #150026 0%, #39048f 60%, #4a06b8 100%);
                color: #ffffff;
                padding: 3rem 1.25rem 3.5rem 1.25rem;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            /* Ambient Rings */
            .mobile-login-hero::before {
                content: "";
                position: absolute;
                top: -60px;
                right: -60px;
                width: 260px;
                height: 260px;
                border-radius: 50%;
                border: 1px solid rgba(255, 255, 255, 0.08);
                box-shadow: 0 0 0 35px rgba(255, 255, 255, 0.03), 0 0 0 70px rgba(255, 255, 255, 0.02);
                pointer-events: none;
            }

            .mobile-hero-badge-wrap {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 12px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 99px;
                backdrop-filter: blur(8px);
                font-size: 0.68rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: #fde047;
                margin-bottom: 1.1rem;
            }

            .mobile-hero-logo {
                width: 68px;
                height: 68px;
                object-fit: contain;
                background: #ffffff;
                border-radius: 18px;
                padding: 7px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.28), 0 0 0 4px rgba(255, 255, 255, 0.15);
                margin-bottom: 0.9rem;
            }

            .mobile-hero-brand {
                font-family: 'Outfit', sans-serif !important;
                font-size: 1.55rem;
                font-weight: 800;
                letter-spacing: -0.02em;
                margin-bottom: 0.25rem;
                color: #ffffff;
            }

            .mobile-hero-sub {
                font-size: 0.8rem;
                color: rgba(255, 255, 255, 0.85);
                font-weight: 400;
                margin-bottom: 1.25rem;
                max-width: 320px;
            }

            /* Feature Highlights */
            .mobile-hero-pills {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                flex-wrap: wrap;
            }

            .mobile-pill {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.16);
                border-radius: 99px;
                padding: 4px 11px;
                font-size: 0.72rem;
                font-weight: 500;
                color: #ffffff;
                backdrop-filter: blur(6px);
            }

            .mobile-pill i {
                font-size: 0.7rem;
                color: #fde047;
            }

            /* ==========================================
               MOBILE AUTH CONTAINER & CARD
               ========================================== */
            .erp-auth-panel {
                width: 100%;
                max-width: 440px;
                padding: 0 1.25rem 2.5rem 1.25rem;
                margin-top: -26px;
                position: relative;
                z-index: 10;
            }

            .erp-auth-card {
                border-radius: 22px !important;
                border: 1px solid rgba(226, 232, 240, 0.8) !important;
                box-shadow: 0 16px 40px -8px rgba(15, 23, 42, 0.08), 0 4px 16px rgba(57, 4, 143, 0.06) !important;
                padding: 1.85rem 1.5rem !important;
                background: #ffffff !important;
            }

            .erp-auth-heading {
                font-size: 1.35rem !important;
            }

            .erp-auth-subtitle {
                font-size: 0.84rem !important;
                margin-bottom: 1.45rem !important;
            }

            .btn-erp-google {
                padding: 14px 16px !important;
                font-size: 0.92rem !important;
                border-radius: 14px !important;
                background: #0f172a !important;
            }

            .erp-auth-footer {
                margin-top: 1.75rem !important;
                font-size: 0.74rem !important;
                color: var(--slate-600) !important;
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
        <header class="mobile-login-hero">
            <div class="mobile-hero-badge-wrap">
                <i class="fa-solid fa-sparkles"></i>
                <span>Enterprise Mobile ERP 2.0</span>
            </div>
            
            <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="Logo" class="mobile-hero-logo">
            
            <h1 class="mobile-hero-brand">Music MSU Connect</h1>
            <p class="mobile-hero-sub">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>
            
            <div class="mobile-hero-pills">
                <span class="mobile-pill"><i class="fa-solid fa-signature"></i> อนุมัติคำร้อง</span>
                <span class="mobile-pill"><i class="fa-solid fa-chart-line"></i> แดชบอร์ด</span>
                <span class="mobile-pill"><i class="fa-solid fa-guitar"></i> ห้องซ้อมดนตรี</span>
            </div>
        </header>

        <!-- =========================================================
             AUTH CARD CONTAINER
             ========================================================= -->
        <main class="erp-auth-panel">
            <div class="erp-auth-card">
                <div class="erp-auth-icon-box">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>

                <h2 class="erp-auth-heading">เข้าสู่ระบบปฏิบัติการ</h2>
                <p class="erp-auth-subtitle">
                    ยืนยันตัวตนผ่านระบบ Single Sign-On (SSO) เพื่อเข้าถึงพื้นที่ทำงานของคุณ
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
                    <span class="flex-grow-1 text-center">เข้าสู่ระบบด้วยบัญชี Google มหาวิทยาลัย</span>
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

            <div class="text-center mb-3">
                    <a href="../mobile/login.php?mobile=1" class="btn btn-sm btn-outline-purple rounded-pill px-3 py-1 extra-small fw-bold" style="border-color: rgba(57, 4, 143, 0.3); color: #39048f; background: rgba(57, 4, 143, 0.04); text-decoration: none;">
                        <i class="fa-solid fa-mobile-screen-button me-1"></i> เข้าสู่ระบบฉบับ Mobile (Music MSU Connect)
                    </a>
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
