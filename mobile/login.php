<?php
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบอุปกรณ์: เฉพาะเมื่อเป็นอุปกรณ์มือถือจริงๆ จึงจำโหมด Mobile ใน Cookie
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_real_mobile = (bool)preg_match('/(android|iphone|ipod|blackberry|windows phone|mobile)/i', $user_agent);

$_SESSION['login_target'] = 'mobile';
if ($is_real_mobile) {
    $_SESSION['app_mode'] = 'mobile';
    setcookie('app_mode', 'mobile', time() + (86400 * 30), '/');
    setcookie('login_from_mobile', '1', time() + 3600, '/');
}

// ถ้าเข้าสู่ระบบแล้ว ให้ไปที่ mobile/index.php ทันที
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// --- [ตั้งค่า Google OAuth 2.0] ---
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
    <title>เข้าสู่ระบบ - Music MSU Connect (Mobile Edition)</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --msu-primary: #39048f;
            --msu-primary-hover: #4a06b8;
            --msu-primary-light: #5b13df;
            --msu-primary-glow: rgba(57, 4, 143, 0.35);
            --msu-surface: #f4efff;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
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
            background-color: var(--slate-50);
            color: var(--slate-900);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .mobile-login-shell {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100%;
            background: #f8fafc;
        }

        /* Ambient Hero Header */
        .mobile-login-header {
            position: relative;
            width: 100%;
            background: radial-gradient(circle at 85% 15%, rgba(91, 19, 223, 0.45) 0%, transparent 60%),
                        radial-gradient(circle at 15% 85%, rgba(197, 160, 40, 0.16) 0%, transparent 45%),
                        linear-gradient(155deg, #150026 0%, #39048f 60%, #4a06b8 100%);
            color: #ffffff;
            padding: 3.25rem 1.25rem 3.75rem 1.25rem;
            text-align: center;
            overflow: hidden;
        }

        .mobile-login-header::before {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 30px rgba(255, 255, 255, 0.03), 0 0 0 60px rgba(255, 255, 255, 0.02);
            pointer-events: none;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 99px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #fde047;
            margin-bottom: 1.1rem;
            backdrop-filter: blur(8px);
        }

        .brand-logo-wrap {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3), 0 0 0 4px rgba(255, 255, 255, 0.16);
        }

        .brand-logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-name {
            font-family: 'Outfit', sans-serif !important;
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 0.25rem;
            color: #ffffff;
        }

        .brand-sub {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.82);
            margin-bottom: 1.25rem;
            font-weight: 400;
        }

        .feature-pills {
            display: flex;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .feat-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 99px;
            padding: 4px 10px;
            font-size: 0.72rem;
            color: #ffffff;
            backdrop-filter: blur(6px);
        }

        .feat-pill i {
            color: #fde047;
            font-size: 0.68rem;
        }

        /* Elevated Card Sheet */
        .mobile-login-card-container {
            width: 100%;
            max-width: 440px;
            margin: -26px auto 0 auto;
            padding: 0 1.25rem 2.5rem 1.25rem;
            position: relative;
            z-index: 10;
        }

        .mobile-login-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 1.85rem 1.5rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 16px 40px -8px rgba(15, 23, 42, 0.08), 0 4px 16px rgba(57, 4, 143, 0.06);
        }

        .card-icon-badge {
            width: 48px;
            height: 48px;
            background: var(--msu-surface);
            color: var(--msu-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--slate-900);
            margin-bottom: 0.35rem;
        }

        .card-desc {
            font-size: 0.84rem;
            color: var(--slate-500);
            line-height: 1.55;
            margin-bottom: 1.45rem;
        }

        /* Google SSO Button */
        .btn-google-sso {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 14px 16px;
            background: #0f172a;
            color: #ffffff !important;
            border: none;
            border-radius: 16px;
            font-size: 0.92rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .btn-google-sso:active {
            transform: scale(0.98);
            background: #020617;
        }

        .google-icon-box {
            width: 32px;
            height: 32px;
            background: #ffffff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .google-icon-box img {
            width: 18px;
            height: 18px;
            object-fit: contain;
        }

        /* Domain Helper Note */
        .domain-note-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--slate-100);
            border: 1px solid var(--slate-200);
            border-radius: 12px;
            padding: 10px 14px;
            margin-top: 1.15rem;
            font-size: 0.76rem;
            color: var(--slate-600);
        }

        .domain-pill {
            font-weight: 700;
            color: var(--msu-primary);
            background: var(--msu-surface);
            padding: 2px 7px;
            border-radius: 6px;
        }

        /* Security Meta */
        .security-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--slate-100);
            font-size: 0.72rem;
            color: var(--slate-400);
        }

        /* Footer Info */
        .mobile-login-footer {
            margin-top: auto;
            text-align: center;
            padding: 1rem 1.25rem 2.5rem 1.25rem;
            font-size: 0.75rem;
            color: var(--slate-500);
        }

        .switch-pc-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--msu-primary);
            text-decoration: none;
            font-weight: 600;
            padding: 6px 14px;
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: 99px;
            margin-top: 0.85rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            transition: 0.2s;
        }

        /* Dark Mode */
        html.dark-mode body, html.dark-mode .mobile-login-shell {
            background-color: #0b0f19 !important;
            color: #f1f5f9 !important;
        }

        html.dark-mode .mobile-login-card {
            background: #141b2d !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.5) !important;
        }

        html.dark-mode .card-title {
            color: #ffffff !important;
        }

        html.dark-mode .card-desc {
            color: #94a3b8 !important;
        }

        html.dark-mode .domain-note-box {
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            color: #cbd5e1 !important;
        }

        html.dark-mode .domain-pill {
            background: rgba(57, 4, 143, 0.3) !important;
            color: #c4b5fd !important;
        }

        html.dark-mode .btn-google-sso {
            background: #39048f !important;
            box-shadow: 0 8px 25px rgba(57, 4, 143, 0.45) !important;
        }

        html.dark-mode .switch-pc-link {
            background: #1e293b !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #c4b5fd !important;
        }
    </style>
</head>
<body>

<div class="mobile-login-shell">
    
    <!-- Hero Header -->
    <header class="mobile-login-header">
        <div class="brand-badge">
            <i class="fa-solid fa-sparkles"></i>
            <span>Enterprise Mobile ERP 2.0</span>
        </div>

        <div class="brand-logo-wrap">
            <img src="<?php echo $base_url; ?>/uploads/logo.png" alt="MSU Music Logo">
        </div>

        <h1 class="brand-name">Music MSU Connect</h1>
        <p class="brand-sub">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>

        <div class="feature-pills">
            <span class="feat-pill"><i class="fa-solid fa-signature"></i> อนุมัติคำร้อง</span>
            <span class="feat-pill"><i class="fa-solid fa-clock-rotate-left"></i> Daily Report</span>
            <span class="feat-pill"><i class="fa-solid fa-guitar"></i> ห้องซ้อมดนตรี</span>
            <span class="feat-pill"><i class="fa-solid fa-video"></i> ห้อง Zoom</span>
        </div>
    </header>

    <!-- Elevated Auth Sheet -->
    <main class="mobile-login-card-container">
        <div class="mobile-login-card">
            <div class="card-icon-badge">
                <i class="fa-solid fa-shield-halved"></i>
            </div>

            <h2 class="card-title">เข้าสู่ระบบปฏิบัติการ</h2>
            <p class="card-desc">
                ยืนยันตัวตนผ่าน Single Sign-On (SSO) ด้วยบัญชีอีเมลมหาวิทยาลัยเพื่อเข้าสู่ระบบ
            </p>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger border-0 d-flex align-items-start gap-2 mb-3 rounded-3" style="font-size: 0.82rem;">
                    <i class="fa-solid fa-circle-exclamation mt-1"></i>
                    <div><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                </div>
            <?php endif; ?>

            <a href="<?php echo $google_auth_url; ?>" class="btn-google-sso">
                <span class="google-icon-box">
                    <img src="<?php echo $base_url; ?>/uploads/gglogo.png" alt="Google">
                </span>
                <span class="flex-grow-1 text-center pe-2">เข้าสู่ระบบด้วย Google มหาวิทยาลัย</span>
                <i class="fa-solid fa-arrow-right extra-small opacity-75"></i>
            </a>

            <div class="domain-note-box">
                <i class="fa-solid fa-circle-info text-purple fs-6 flex-shrink-0"></i>
                <div>
                    กรุณาใช้อีเมลบุคลากร <span class="domain-pill">@msu.ac.th</span> ในการเข้าสู่ระบบเท่านั้น
                </div>
            </div>

            <div class="security-meta-row">
                <span><i class="fa-solid fa-lock me-1"></i> OAuth 2.0 Encrypted</span>
                <span>Mobile ERP Build 2026.2</span>
            </div>
        </div>

        <div class="text-center mt-3">
            <a href="../auth/login.php?pc=1" class="switch-pc-link">
                <i class="fa-solid fa-desktop"></i> เข้าใช้งานเวอร์ชัน PC (Desktop)
            </a>
        </div>

        <div class="mobile-login-footer">
            <div>พบปัญหาการเข้าใช้งาน กรุณาติดต่อ <strong>งานเทคโนโลยีสารสนเทศ</strong><br>วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</div>
        </div>
    </main>

</div>

<script>
    // Sync dark mode preference with main mobile app
    if (localStorage.getItem('mobile_theme') === 'dark' || (!localStorage.getItem('mobile_theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark-mode');
    }
</script>

</body>
</html>
