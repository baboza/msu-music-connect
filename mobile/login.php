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

// เมื่อเข้าใช้งานหน้า mobile/login.php ให้ตั้งค่าเป็น Mobile App ชัดเจน
$_SESSION['login_target'] = 'mobile';
$_SESSION['prefer_desktop'] = false;
$_SESSION['app_mode'] = 'mobile';
setcookie('app_mode', 'mobile', time() + (86400 * 30), '/');

// หากผู้ใช้จงใจคลิกสลับไปหน้า PC Login ผ่านลิงก์ (?pc=1)
if (isset($_GET['pc'])) {
    $_SESSION['login_target'] = 'pc';
    $_SESSION['prefer_desktop'] = true;
    $_SESSION['app_mode'] = 'desktop';
    setcookie('app_mode', 'desktop', time() + 86400 * 30, '/');
    header("Location: ../auth/login.php?pc=1");
    exit();
}

// ถ้าเข้าสู่ระบบแล้ว ให้ไปที่ mobile/index.php ทันที
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// --- [ตั้งค่า Google OAuth 2.0] ---
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
    'state' => 'mobile' // ส่ง state=mobile เพื่อให้ callback redirect มายัง mobile/index.php
]);
$base_url = "/music-e-office";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>เข้าสู่ระบบ - Music MSU Connect (Mobile App)</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --app-bg-start: #0f172a;
            --app-bg-mid: #1e1b4b;
            --app-bg-end: #312e81;
            --app-accent-indigo: #4f46e5;
            --app-accent-violet: #7c3aed;
            --app-accent-cyan: #06b6d4;
            --app-accent-emerald: #10b981;
            
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
            box-sizing: border-box;
            font-family: 'Anuphan', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background: #f8fafc;
            color: var(--slate-900);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .mobile-app-shell {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        /* ---------------------------------------------------------
           1. VIBRANT MOBILE APP HERO
           --------------------------------------------------------- */
        .mobile-app-hero {
            background: radial-gradient(circle at 85% 20%, rgba(99, 102, 241, 0.45) 0%, transparent 55%),
                        radial-gradient(circle at 15% 85%, rgba(6, 182, 212, 0.3) 0%, transparent 50%),
                        linear-gradient(160deg, #171c38 0%, #1e1b4b 40%, #312e81 85%, #4338ca 100%);
            color: #ffffff;
            padding: 2.75rem 1.25rem 2.85rem 1.25rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* Ambient glowing circles */
        .mobile-app-hero::before {
            content: "";
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
            width: 340px;
            height: 340px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.25) 0%, transparent 70%);
            pointer-events: none;
        }

        /* Smartphone App Squircle Icon */
        .app-squircle-box {
            position: relative;
            width: 78px;
            height: 78px;
            margin: 0 auto 1.1rem auto;
            border-radius: 22px;
            background: #ffffff;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35), 0 0 0 4px rgba(255, 255, 255, 0.18);
        }

        .app-squircle-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .app-mobile-tag {
            position: absolute;
            bottom: -7px;
            right: -7px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            border: 2px solid #ffffff;
            box-shadow: 0 3px 8px rgba(0,0,0,0.25);
        }

        .mobile-brand-title {
            font-family: 'Outfit', sans-serif !important;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.25rem;
            color: #ffffff;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .mobile-brand-subtitle {
            font-size: 0.84rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 0.85rem;
            font-weight: 400;
        }

        .mobile-edition-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(6, 182, 212, 0.18);
            border: 1px solid rgba(6, 182, 212, 0.4);
            border-radius: 99px;
            font-size: 0.74rem;
            font-weight: 700;
            color: #67e8f9;
            backdrop-filter: blur(8px);
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.35);
            animation: pulseAnimation 1.8s infinite;
        }

        @keyframes pulseAnimation {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* ---------------------------------------------------------
           3. AUTH CARD CONTENT
           --------------------------------------------------------- */
        .mobile-auth-body {
            position: relative;
            background: #ffffff;
            border-radius: 28px 28px 0 0;
            margin-top: -20px;
            padding: 1.6rem 1.25rem 2rem 1.25rem;
            box-shadow: 0 -8px 24px rgba(15, 23, 42, 0.08);
            flex: 1;
        }

        /* Version Indicator Callout */
        .mobile-version-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            border: 1px solid #c7d2fe;
            border-radius: 16px;
            padding: 11px 14px;
            margin-bottom: 1.35rem;
        }

        .mvb-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #4f46e5;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .mvb-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1e1b4b;
            margin-bottom: 1px;
        }

        .mvb-desc {
            font-size: 0.72rem;
            color: #4338ca;
            line-height: 1.3;
        }

        /* Modern White Google SSO Button with Electric Accent */
        .btn-google-mobile {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 12px 14px;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            text-decoration: none;
            box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.08), 0 2px 6px rgba(0, 0, 0, 0.04);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .btn-google-mobile:active {
            transform: scale(0.98);
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .google-brand-icon {
            width: 38px;
            height: 38px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .google-brand-icon img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        .google-btn-info {
            flex: 1;
            text-align: left;
            padding: 0 12px;
        }

        .google-btn-info .main-lbl {
            display: block;
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.25;
        }

        .google-btn-info .sub-lbl {
            display: block;
            font-size: 0.72rem;
            color: #64748b;
        }

        .btn-google-mobile .arrow-icon {
            color: #4f46e5;
            font-size: 0.9rem;
            padding-right: 4px;
        }

        /* Domain Helper Box */
        .domain-badge-alert {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 9px 12px;
            margin-top: 1rem;
            font-size: 0.76rem;
            color: var(--slate-600);
        }

        .domain-tag {
            font-weight: 700;
            color: #4f46e5;
            background: #eef2ff;
            padding: 2px 7px;
            border-radius: 6px;
        }

        /* Mobile Feature Highlights (2x2 Grid) */
        .mobile-features-section {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--slate-100);
        }

        .feature-grid-title {
            font-size: 0.76rem;
            font-weight: 700;
            color: var(--slate-400);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            text-align: center;
        }

        .feature-grid-2x2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .feat-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid var(--slate-100);
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 0.74rem;
            font-weight: 500;
            color: var(--slate-700);
        }

        .feat-box i {
            font-size: 0.85rem;
        }

        .feat-box.c-purple i { color: #7c3aed; }
        .feat-box.c-blue i { color: #0284c7; }
        .feat-box.c-amber i { color: #d97706; }
        .feat-box.c-emerald i { color: #059669; }

        /* ---------------------------------------------------------
           4. DEDICATED PC SWITCHER CARD
           --------------------------------------------------------- */
        .switch-to-pc-card {
            margin-top: 1.5rem;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 14px;
            text-align: center;
        }

        .switch-pc-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--slate-800);
            margin-bottom: 2px;
        }

        .switch-pc-desc {
            font-size: 0.72rem;
            color: var(--slate-500);
            margin-bottom: 10px;
        }

        .btn-switch-pc {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 9px 14px;
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            transition: 0.15s;
        }

        .btn-switch-pc:hover, .btn-switch-pc:active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }

        /* Footer */
        .mobile-app-footer {
            text-align: center;
            padding: 1.25rem 1rem 2rem 1rem;
            font-size: 0.72rem;
            color: var(--slate-400);
        }

        /* Dark Mode Support */
        html.dark-mode body, html.dark-mode .mobile-app-shell {
            background-color: #0b0f19 !important;
            color: #f1f5f9 !important;
        }

        html.dark-mode .mobile-auth-body {
            background: #111827 !important;
            box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.4) !important;
        }

        html.dark-mode .mobile-version-banner {
            background: rgba(79, 70, 229, 0.15) !important;
            border-color: rgba(79, 70, 229, 0.35) !important;
        }

        html.dark-mode .mvb-title {
            color: #c7d2fe !important;
        }

        html.dark-mode .mvb-desc {
            color: #a5b4fc !important;
        }

        html.dark-mode .btn-google-mobile {
            background: #1f2937 !important;
            border-color: #374151 !important;
        }

        html.dark-mode .google-brand-icon {
            background: #111827 !important;
            border-color: #374151 !important;
        }

        html.dark-mode .google-btn-info .main-lbl {
            color: #f9fafb !important;
        }

        html.dark-mode .google-btn-info .sub-lbl {
            color: #9ca3af !important;
        }

        html.dark-mode .domain-badge-alert {
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        html.dark-mode .feat-box {
            background: #1f2937 !important;
            border-color: #374151 !important;
            color: #e2e8f0 !important;
        }

        html.dark-mode .switch-to-pc-card {
            background: #1f2937 !important;
            border-color: #374151 !important;
        }

        html.dark-mode .switch-pc-title {
            color: #f3f4f6 !important;
        }

        html.dark-mode .btn-switch-pc {
            background: #111827 !important;
            border-color: #4b5563 !important;
            color: #e2e8f0 !important;
        }
    </style>
</head>
<body>

<div class="mobile-app-shell">
    
    <!-- Mobile App Hero Banner -->
    <header class="mobile-app-hero">
        <div class="app-squircle-box">
            <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="MSU Music Logo">
            <div class="app-mobile-tag" title="Mobile App Edition">
                <i class="fa-solid fa-mobile-screen"></i>
            </div>
        </div>

        <h1 class="mobile-brand-title">Music MSU Connect</h1>
        <p class="mobile-brand-subtitle">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>

        <div class="mobile-edition-badge">
            <span class="pulse-dot"></span>
            <span>เวอร์ชันแอปพลิเคชันมือถือ (Mobile Edition)</span>
        </div>
    </header>

    <!-- 3. Auth Body Sheet -->
    <main class="mobile-auth-body">
        
        <!-- Callout Banner: Clear Version Identification -->
        <div class="mobile-version-banner">
            <div class="mvb-icon">
                <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
            <div>
                <div class="mvb-title">คุณกำลังเข้าสู่ระบบเวอร์ชัน Mobile App</div>
                <div class="mvb-desc">ปรับรูปแบบการแสดงผลและการใช้งานเฉพาะสมาร์ทโฟน</div>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger border-0 d-flex align-items-start gap-2 mb-3 rounded-3" style="font-size: 0.82rem;">
                <i class="fa-solid fa-circle-exclamation mt-1"></i>
                <div><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Google SSO Modern White Button -->
        <a href="<?php echo $google_auth_url; ?>" class="btn-google-mobile">
            <span class="google-brand-icon">
                <img src="<?php echo $base_url; ?>/uploads/gglogo.png" alt="Google">
            </span>
            <span class="google-btn-info">
                <span class="main-text main-lbl">เข้าสู่ระบบด้วย Google มหาวิทยาลัย</span>
                <span class="sub-text sub-lbl">เข้าใช้งานเวอร์ชัน Mobile ทันที</span>
            </span>
            <i class="fa-solid fa-chevron-right arrow-icon"></i>
        </a>

        <!-- Domain Notice -->
        <div class="domain-badge-alert">
            <i class="fa-solid fa-circle-info text-primary fs-6 flex-shrink-0"></i>
            <div>
                กรุณาใช้อีเมลบุคลากร <span class="domain-tag">@msu.ac.th</span> ในการเข้าสู่ระบบ
            </div>
        </div>

        <!-- Mobile App Features 2x2 Grid -->
        <div class="mobile-features-section">
            <div class="feature-grid-title">ฟีเจอร์เด่นบนมือถือ</div>
            <div class="feature-grid-2x2">
                <div class="feat-box c-purple">
                    <i class="fa-solid fa-signature"></i>
                    <span>อนุมัติคำร้องฉับไว</span>
                </div>
                <div class="feat-box c-blue">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>บันทึก Daily Report</span>
                </div>
                <div class="feat-box c-amber">
                    <i class="fa-solid fa-guitar"></i>
                    <span>จองห้องซ้อมดนตรี</span>
                </div>
                <div class="feat-box c-emerald">
                    <i class="fa-solid fa-bell"></i>
                    <span>แจ้งเตือนสถานะทันที</span>
                </div>
            </div>
        </div>

        <!-- PC Switcher Card -->
        <div class="switch-to-pc-card">
            <div class="switch-pc-title"><i class="fa-solid fa-desktop me-1"></i> ใช้งานบนคอมพิวเตอร์?</div>
            <div class="switch-pc-desc">หากต้องการหน้าจอแบบเต็มและเครื่องมือจัดการสำหรับ PC</div>
            <a href="../auth/login.php?pc=1" class="btn-switch-pc">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> สลับไปหน้าเข้าสู่ระบบ PC (Desktop)
            </a>
        </div>

        <div class="mobile-app-footer">
            <div>ระบบสารสนเทศองค์กร วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
            <div class="mt-1">พบปัญหาการใช้งาน ติดต่อ <strong>งานเทคโนโลยีสารสนเทศ</strong></div>
        </div>

    </main>

</div>

<script>
    // Sync dark mode preference with mobile app
    if (localStorage.getItem('mobile_theme') === 'dark' || (!localStorage.getItem('mobile_theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark-mode');
    }
</script>

</body>
</html>