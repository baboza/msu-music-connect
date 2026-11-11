<?php
/**
 * Music MSU Connect 2.0 - Prototype Mobile Home
 * Primary Theme: #39048f (Electric Royal Indigo)
 * Designed for High-End Enterprise & Executive Experience
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fallback session for prototype preview if not logged in
$user_id = $_SESSION['user_id'] ?? '2d67fd96';
$full_name = $_SESSION['full_name'] ?? 'อาจารย์ จิรวัฒน์ สุราสา';
$role_title = 'รองคณบดีฝ่ายบริหารและวิชาการ';
$avatar_url = '../uploads/logo.png';

// Try loading real database metrics if available
$pending_approvals = 9;
$pending_leaves = 4;
$pending_supplies = 5;
$leave_balance = 10;
$today_meeting_title = 'การประชุมสำนักงานเลขานุการ 3/2569';
$today_meeting_time = '13:30 - 15:30 น.';
$today_meeting_room = 'ห้องประชุม 2 ชั้น 3';

if (file_exists('../config/db.php')) {
    try {
        require_once '../config/db.php';
        $cnt_l = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        $cnt_s = (int)$pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE head_status = 'pending' OR supply_status = 'pending'")->fetchColumn();
        $pending_leaves = $cnt_l;
        $pending_supplies = $cnt_s;
        $pending_approvals = $cnt_l + $cnt_s;

        // ดึงชื่อและรูป
        $u_stmt = $pdo->prepare("SELECT full_name, profile_picture FROM users WHERE user_id = ?");
        $u_stmt->execute([$user_id]);
        $u_data = $u_stmt->fetch(PDO::FETCH_ASSOC);
        if ($u_data) {
            $full_name = $u_data['full_name'] ?: $full_name;
            if (!empty($u_data['profile_picture'])) {
                $avatar_url = '../uploads/avatars/' . $u_data['profile_picture'];
            }
        }

        // ดึงการประชุมล่าสุด
        $m_stmt = $pdo->query("SELECT title, meeting_no, start_time, end_time, location FROM meetings ORDER BY meeting_date DESC LIMIT 1");
        $m_data = $m_stmt->fetch(PDO::FETCH_ASSOC);
        if ($m_data) {
            $today_meeting_title = $m_data['title'] . ' ' . $m_data['meeting_no'];
            $today_meeting_time = date('H:i', strtotime($m_data['start_time'])) . ' น.';
            $today_meeting_room = $m_data['location'] ?: 'ห้องประชุมวิทยาลัยฯ';
        }
    } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Music MSU Connect 2.0 - Prototype (#39048f)</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome 6 Pro/Free -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            /* Primary Electric Indigo Theme (#39048f) */
            --primary: #39048f;
            --primary-light: #520ebd;
            --primary-glow: #6f26df;
            --primary-surface: #f4efff;
            --primary-subtle: rgba(57, 4, 143, 0.06);
            --primary-border: rgba(57, 4, 143, 0.12);
            
            --primary-gradient: linear-gradient(135deg, #24005e 0%, #39048f 52%, #5e18d6 100%);
            --hero-gradient: linear-gradient(140deg, #280268 0%, #39048f 60%, #6318e8 100%);
            --card-glass: rgba(255, 255, 255, 0.94);
            
            /* Slate Neutrals */
            --bg-canvas: #f6f7fb;
            --surface-card: #ffffff;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --border-subtle: #e2e8f0;
            
            /* Executive Accents */
            --accent-gold: #c69214;
            --accent-gold-subtle: #fef9c3;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            
            /* Shadows & Radii */
            --radius-card: 22px;
            --radius-pill: 9999px;
            --shadow-card: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 1px 3px rgba(0, 0, 0, 0.02);
            --shadow-float: 0 14px 34px -6px rgba(57, 4, 143, 0.35);
            --shadow-dock: 0 -8px 30px rgba(15, 23, 42, 0.07);
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-canvas);
            color: var(--text-body);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        .font-outfit {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
        }

        /* Prototype Frame Toggle for Desktop View */
        .preview-bar {
            background: #111827;
            color: #94a3b8;
            padding: 8px 16px;
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 2000;
        }
        .preview-badge {
            background: #39048f;
            color: #ffffff;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        /* Mobile Device Canvas Wrapper */
        .device-wrapper {
            max-width: 440px;
            margin: 0 auto;
            background: #ffffff;
            min-height: 100vh;
            box-shadow: 0 0 50px rgba(0, 0, 0, 0.08);
            position: relative;
            padding-bottom: 100px;
        }

        /* Top Mobile Status Header */
        .mobile-header {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            padding: 12px 18px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.05rem;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .brand-pill {
            background: var(--primary);
            color: #ffffff;
            font-size: 0.65rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .header-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: var(--primary-subtle);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            position: relative;
            border: 1px solid var(--primary-border);
            transition: transform 0.15s ease;
        }
        .header-icon-btn:active {
            transform: scale(0.92);
        }
        .badge-dot {
            position: absolute;
            top: -3px;
            right: -3px;
            background: var(--accent-rose);
            color: #ffffff;
            font-size: 0.62rem;
            font-weight: 700;
            height: 18px;
            min-width: 18px;
            border-radius: 99px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        /* Executive Greeting Section */
        .greeting-section {
            padding: 18px 20px 10px 20px;
        }
        .greeting-sub {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.3px;
        }
        .greeting-title {
            font-size: 1.32rem;
            font-weight: 700;
            color: var(--text-heading);
            margin: 2px 0 4px 0;
            letter-spacing: -0.3px;
        }
        .executive-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-surface);
            color: var(--primary);
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: var(--radius-pill);
            border: 1px solid var(--primary-border);
        }

        /* Hero Attendance Card (#39048f Gradient with Ripple Watermark) */
        .hero-attendance-card {
            margin: 10px 18px 18px 18px;
            border-radius: 24px;
            background: var(--hero-gradient);
            color: #ffffff;
            padding: 22px 20px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-float);
        }

        /* Decorative concentric rings */
        .hero-attendance-card::before {
            content: '';
            position: absolute;
            right: -40px;
            top: -40px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            border: 18px solid rgba(255, 255, 255, 0.05);
            pointer-events: none;
        }
        .hero-attendance-card::after {
            content: '';
            position: absolute;
            right: -80px;
            top: -80px;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            border: 24px solid rgba(255, 255, 255, 0.03);
            pointer-events: none;
        }

        .hero-date-box {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            padding: 8px 12px;
            text-align: center;
            min-width: 58px;
            border: 1px solid rgba(255, 255, 255, 0.25);
        }
        .hero-date-day {
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1;
            font-family: 'Outfit', sans-serif;
        }
        .hero-date-mon {
            font-size: 0.7rem;
            font-weight: 600;
            opacity: 0.9;
        }

        .hero-action-btn-white {
            background: #ffffff;
            color: var(--primary);
            font-weight: 700;
            font-size: 0.85rem;
            padding: 10px 18px;
            border-radius: var(--radius-pill);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
            transition: all 0.18s ease;
            border: none;
        }
        .hero-action-btn-white:active {
            transform: scale(0.96);
            background: #f8fafc;
        }

        .hero-action-btn-glass {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.82rem;
            padding: 9px 16px;
            border-radius: var(--radius-pill);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            transition: all 0.18s ease;
        }
        .hero-action-btn-glass:active {
            background: rgba(255, 255, 255, 0.2);
        }

        /* 2-Column Bento Hub */
        .bento-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 0 18px 18px 18px;
        }
        .bento-card-compact {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            padding: 16px;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .bento-card-compact:active {
            transform: scale(0.97);
        }

        /* Mini Calendar Strip (Like Image 2 & 3) */
        .calendar-strip-wrap {
            padding: 0 18px 16px 18px;
        }
        .calendar-strip {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .calendar-strip::-webkit-scrollbar {
            display: none;
        }
        .calendar-day-pill {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 10px 14px;
            min-width: 58px;
            text-align: center;
            text-decoration: none;
            color: var(--text-muted);
            transition: all 0.18s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            flex-shrink: 0;
        }
        .calendar-day-pill.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 6px 18px rgba(57, 4, 143, 0.35);
            transform: translateY(-2px);
        }
        .calendar-day-num {
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1.1;
            font-family: 'Outfit', sans-serif;
        }
        .calendar-day-name {
            font-size: 0.68rem;
            font-weight: 600;
            margin-top: 2px;
        }

        /* Priority Agenda / Meeting Card (Image 3 Inspiration) */
        .agenda-card {
            margin: 0 18px 18px 18px;
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            padding: 16px;
            box-shadow: var(--shadow-card);
            position: relative;
        }
        .agenda-pill-time {
            background: var(--primary-surface);
            color: var(--primary);
            font-weight: 700;
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Segmented Service Tabs */
        .category-tab-scroll {
            display: flex;
            gap: 8px;
            padding: 0 18px 12px 18px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .category-tab-scroll::-webkit-scrollbar {
            display: none;
        }
        .cat-pill {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 7px 15px;
            border-radius: var(--radius-pill);
            white-space: nowrap;
            text-decoration: none;
            transition: all 0.15s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
            border: 1px solid var(--border-subtle);
        }
        .cat-pill.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(57, 4, 143, 0.25);
        }

        /* 3-Column Modern Service Grid */
        .service-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 0 18px 24px 18px;
        }
        .service-tile {
            background: #ffffff;
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            padding: 16px 10px 14px 10px;
            text-align: center;
            text-decoration: none;
            color: var(--text-heading);
            box-shadow: var(--shadow-card);
            transition: all 0.18s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .service-tile:active {
            transform: scale(0.93);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .service-icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: var(--primary-subtle);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 8px;
            border: 1px solid var(--primary-border);
            transition: all 0.18s ease;
        }
        .service-tile:hover .service-icon-wrap {
            background: var(--primary);
            color: #ffffff;
        }
        .service-tile-name {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 3px;
            line-height: 1.25;
        }
        .service-tile-badge {
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: var(--radius-pill);
            background: #f1f5f9;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        /* Floating Modern Bottom Dock */
        .bottom-dock {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            max-width: 440px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-top: 1px solid rgba(226, 232, 240, 0.85);
            padding: 10px 16px calc(12px + env(safe-area-inset-bottom)) 16px;
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1040;
            box-shadow: var(--shadow-dock);
        }
        .dock-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.68rem;
            font-weight: 600;
            gap: 3px;
            position: relative;
            padding: 4px 12px;
            border-radius: 12px;
            transition: all 0.18s ease;
        }
        .dock-item i {
            font-size: 1.22rem;
            transition: transform 0.18s ease;
        }
        .dock-item.active {
            color: var(--primary);
            font-weight: 700;
        }
        .dock-item.active i {
            transform: scale(1.1);
        }
        .dock-badge {
            position: absolute;
            top: 1px;
            right: 12px;
            background: var(--accent-rose);
            color: #ffffff;
            font-size: 0.6rem;
            font-weight: 800;
            height: 16px;
            min-width: 16px;
            border-radius: 99px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        /* Floating Action Button (FAB) */
        .prototype-fab {
            position: fixed;
            bottom: calc(76px + env(safe-area-inset-bottom));
            right: calc(50% - 200px);
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            box-shadow: 0 10px 25px rgba(57, 4, 143, 0.45);
            border: 2.5px solid #ffffff;
            z-index: 1050;
            text-decoration: none;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @media (max-width: 440px) {
            .prototype-fab {
                right: 20px;
            }
        }
        .prototype-fab:active {
            transform: scale(0.9);
        }
    </style>
</head>
<body>

    <!-- Top Preview Control Bar -->
    <div class="preview-bar">
        <div class="d-flex align-items-center gap-2">
            <span class="preview-badge">PROTOTYPE</span>
            <span class="text-white fw-bold">ERP 2.0 Mobile</span>
            <span class="badge bg-secondary" style="font-size: 0.65rem;">Theme: #39048f</span>
        </div>
        <div>
            <a href="index.php" class="text-white text-decoration-none extra-small">
                <i class="fa-solid fa-arrow-left me-1"></i>กลับระบบจริง
            </a>
        </div>
    </div>

    <!-- Mobile Device Canvas -->
    <div class="device-wrapper">

        <!-- Top Header Navigation -->
        <header class="mobile-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <img src="../uploads/logo.png" alt="Logo" width="34" height="34" style="object-fit: contain;">
                <div>
                    <div class="brand-badge">
                        Music MSU Connect <span class="brand-pill">2.0</span>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="#" class="header-icon-btn" title="ค้นหาด่วน">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 0.95rem;"></i>
                </a>
                <a href="approvals.php" class="header-icon-btn" title="แจ้งเตือน">
                    <i class="fa-regular fa-bell" style="font-size: 1rem;"></i>
                    <span class="badge-dot"><?= $pending_approvals ?></span>
                </a>
                <a href="profile.php" class="d-block text-decoration-none">
                    <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Avatar" width="36" height="36" class="rounded-circle" style="border: 2px solid var(--primary); object-fit: cover;">
                </a>
            </div>
        </header>

        <!-- Greeting Section -->
        <section class="greeting-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="greeting-sub">สวัสดีตอนเช้า ✨</span>
                    <h1 class="greeting-title"><?= htmlspecialchars($full_name) ?></h1>
                    <div class="executive-pill">
                        <i class="fa-solid fa-shield-halved text-purple" style="font-size: 0.7rem;"></i>
                        <?= htmlspecialchars($role_title) ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- HERO ATTENDANCE CARD (#39048f) -->
        <section class="hero-attendance-card">
            <div class="d-flex justify-content-between align-items-start mb-3 position-relative z-1">
                <div class="d-flex align-items-center gap-3">
                    <div class="hero-date-box">
                        <div class="hero-date-day">10</div>
                        <div class="hero-date-mon">พ.ย. 69</div>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-success bg-opacity-25 text-white border border-white border-opacity-25 px-2 py-1" style="font-size: 0.68rem;">
                                <i class="fa-solid fa-circle-check me-1"></i>บันทึกเวลาแล้ว
                            </span>
                            <small class="text-white-50" style="font-size: 0.72rem;">08:24 น. (ตรงเวลา)</small>
                        </div>
                        <h6 class="fw-bold mb-0 text-white" style="font-size: 1rem;">เวลาปฏิบัติงาน (สแกนจุดบริการกลาง)</h6>
                        <small class="text-white-50" style="font-size: 0.7rem;">อาคารวิทยาลัยดุริยางคศิลป์ มมส.</small>
                    </div>
                </div>
            </div>

            <!-- Hero Action Buttons -->
            <div class="d-flex gap-2 mt-3 pt-2 border-top border-white border-opacity-10 position-relative z-1">
                <a href="worklog_form.php" class="hero-action-btn-white flex-fill">
                    <i class="fa-solid fa-pen-nib"></i> เขียน Daily Report
                </a>
                <a href="worklog.php" class="hero-action-btn-glass flex-fill">
                    <i class="fa-solid fa-clock-rotate-left"></i> ดูประวัติงาน
                </a>
            </div>
        </section>

        <!-- 2-COLUMN BENTO HUB (Approvals & Leave Balance) -->
        <section class="bento-grid-2">
            <!-- Left: Universal Approvals -->
            <a href="approvals.php" class="bento-card-compact">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="extra-small fw-bold text-muted">งานรอการอนุมัติ</span>
                    <span class="badge bg-danger rounded-pill px-2 py-1" style="font-size: 0.62rem;">ด่วน</span>
                </div>
                <div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="font-outfit fw-bold text-dark" style="font-size: 2.1rem; line-height: 1;"><?= $pending_approvals ?></span>
                        <span class="small text-muted fw-semibold">รายการ</span>
                    </div>
                    <div class="d-flex gap-1 mt-2">
                        <span class="badge bg-purple-subtle text-purple" style="font-size: 0.65rem; background: var(--primary-surface); color: var(--primary);">ใบลา: <?= $pending_leaves ?></span>
                        <span class="badge bg-purple-subtle text-purple" style="font-size: 0.65rem; background: var(--primary-surface); color: var(--primary);">พัสดุ: <?= $pending_supplies ?></span>
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center text-purple fw-bold extra-small" style="color: var(--primary);">
                    <span>เปิดศูนย์พิจารณา</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- Right: Leave Balance & Quick Leave Request -->
            <a href="leave.php" class="bento-card-compact">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="extra-small fw-bold text-muted">โควตาการลาคงเหลือ</span>
                    <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1" style="font-size: 0.62rem;">พร้อมใช้</span>
                </div>
                <div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="font-outfit fw-bold text-dark" style="font-size: 2.1rem; line-height: 1;"><?= $leave_balance ?></span>
                        <span class="small text-muted fw-semibold">วัน</span>
                    </div>
                    <div class="progress mt-2" style="height: 6px; background: #e2e8f0; border-radius: 99px;">
                        <div class="progress-bar" role="progressbar" style="width: 70%; background: var(--primary-gradient); border-radius: 99px;"></div>
                    </div>
                    <small class="text-muted extra-small d-block mt-1">ลาพักผ่อน (ใช้ไป 2 จาก 12)</small>
                </div>
                <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center text-purple fw-bold extra-small" style="color: var(--primary);">
                    <span>+ ยื่นใบลาใหม่</span>
                    <i class="fa-solid fa-plus"></i>
                </div>
            </a>
        </section>

        <!-- HORIZONTAL MINI CALENDAR STRIP (7 Days View) -->
        <section class="calendar-strip-wrap">
            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                <span class="extra-small fw-bold text-muted text-uppercase tracking-wider">พฤศจิกายน 2569</span>
                <span class="extra-small fw-bold text-purple" style="color: var(--primary);"><i class="fa-regular fa-calendar-check me-1"></i>สัปดาห์ที่ 14</span>
            </div>
            <div class="calendar-strip">
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">อา.</div>
                    <div class="calendar-day-num">08</div>
                </a>
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">จ.</div>
                    <div class="calendar-day-num">09</div>
                </a>
                <a href="#" class="calendar-day-pill active">
                    <div class="calendar-day-name">อ.</div>
                    <div class="calendar-day-num">10</div>
                </a>
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">พ.</div>
                    <div class="calendar-day-num">11</div>
                </a>
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">พฤ.</div>
                    <div class="calendar-day-num">12</div>
                </a>
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">ศ.</div>
                    <div class="calendar-day-num">13</div>
                </a>
                <a href="#" class="calendar-day-pill">
                    <div class="calendar-day-name">ส.</div>
                    <div class="calendar-day-num">14</div>
                </a>
            </div>
        </section>

        <!-- TODAY'S PRIORITY AGENDA / MEETING -->
        <section class="agenda-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="agenda-pill-time">
                    <i class="fa-regular fa-clock"></i> <?= htmlspecialchars($today_meeting_time) ?>
                </span>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill" style="font-size: 0.65rem;">
                    <i class="fa-solid fa-circle-dot me-1 text-success"></i>วาระสำคัญ
                </span>
            </div>
            <h6 class="fw-bold text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;">
                <?= htmlspecialchars($today_meeting_title) ?>
            </h6>
            <div class="text-muted extra-small mb-2">
                <i class="fa-solid fa-location-dot me-1 text-secondary"></i> <?= htmlspecialchars($today_meeting_room) ?>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
                <span class="extra-small text-muted">ผู้เข้าร่วม 12 ท่าน</span>
                <a href="../modules/meeting/view.php" class="btn btn-sm btn-outline-primary rounded-pill extra-small fw-bold px-3" style="border-color: var(--primary); color: var(--primary);">
                    <i class="fa-solid fa-file-lines me-1"></i>เปิดวาระประชุม
                </a>
            </div>
        </section>

        <!-- CATEGORY SWITCHER TABS -->
        <div class="d-flex justify-content-between align-items-center px-3 mb-2">
            <span class="fw-bold text-dark small">บริการและระบบงาน</span>
            <span class="text-muted extra-small">12 บริการ</span>
        </div>
        <div class="category-tab-scroll">
            <button class="cat-pill active" onclick="filterServices('all', this)">ทั้งหมด</button>
            <button class="cat-pill" onclick="filterServices('staff', this)">บริการบุคลากร</button>
            <button class="cat-pill" onclick="filterServices('academic', this)">วิชาการ & ประชุม</button>
            <button class="cat-pill" onclick="filterServices('exec', this)">สำหรับผู้บริหาร</button>
        </div>

        <!-- 3-COLUMN SERVICE GRID (Redesigned with #39048f Elegance) -->
        <section class="service-grid-3" id="serviceGrid">
            
            <!-- 1. Daily Report -->
            <a href="worklog_form.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="service-tile-name">Daily Report</div>
                <span class="service-tile-badge"><i class="fa-solid fa-circle text-purple" style="font-size: 5px; color: var(--primary);"></i> บันทึกงาน</span>
            </a>

            <!-- 2. ระบบการลา -->
            <a href="leave.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </div>
                <div class="service-tile-name">ระบบการลา</div>
                <span class="service-tile-badge"><i class="fa-solid fa-circle text-success" style="font-size: 5px;"></i> เหลือ <?= $leave_balance ?> วัน</span>
            </a>

            <!-- 3. ไปราชการ/อบรม -->
            <a href="seminars.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-plane-departure"></i>
                </div>
                <div class="service-tile-name">ไปราชการ/อบรม</div>
                <span class="service-tile-badge">5 ขั้นตอน</span>
            </a>

            <!-- 4. จองห้อง/สถานที่ -->
            <a href="rooms.php" class="service-tile" data-cat="academic">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-door-open"></i>
                </div>
                <div class="service-tile-name">จองห้องประชุม</div>
                <span class="service-tile-badge"><i class="fa-solid fa-circle text-success" style="font-size: 5px;"></i> ว่าง 3 ห้อง</span>
            </a>

            <!-- 5. แจ้งซ่อมบำรุง -->
            <a href="repairs.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
                <div class="service-tile-name">แจ้งซ่อมบำรุง</div>
                <span class="service-tile-badge">ครุภัณฑ์</span>
            </a>

            <!-- 6. เบิกพัสดุ -->
            <a href="supplies.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="service-tile-name">เบิกพัสดุ</div>
                <span class="service-tile-badge">วัสดุสำนักงาน</span>
            </a>

            <!-- 7. จองห้องซ้อมดนตรี -->
            <a href="practice_rooms.php" class="service-tile" data-cat="academic">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-music"></i>
                </div>
                <div class="service-tile-name">จองห้องซ้อม</div>
                <span class="service-tile-badge"><i class="fa-solid fa-circle text-success" style="font-size: 5px;"></i> Live Map</span>
            </a>

            <!-- 8. ประวัติอาจารย์/CV -->
            <a href="cv.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-id-card-clip"></i>
                </div>
                <div class="service-tile-name">ประวัติ/CV</div>
                <span class="service-tile-badge">ผลงานวิชาการ</span>
            </a>

            <!-- 9. รายการการเงิน -->
            <a href="finance.php" class="service-tile" data-cat="staff">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div class="service-tile-name">สลิปการเงิน</div>
                <span class="service-tile-badge">เบิกจ่าย/สวัสดิการ</span>
            </a>

            <!-- 10. คำร้องวิชาการ -->
            <a href="../modules/open_course/index.php" class="service-tile" data-cat="academic">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="service-tile-name">คำร้องวิชาการ</div>
                <span class="service-tile-badge">นิสิต/เปิดวิชา</span>
            </a>

            <!-- 11. ระบบการประชุม -->
            <a href="../modules/meeting/index.php" class="service-tile" data-cat="academic">
                <div class="service-icon-wrap">
                    <i class="fa-solid fa-handshake"></i>
                </div>
                <div class="service-tile-name">ระบบการประชุม</div>
                <span class="service-tile-badge">e-Meeting</span>
            </a>

            <!-- 12. ศูนย์อนุมัติคำร้อง (Executive) -->
            <a href="approvals.php" class="service-tile" data-cat="exec">
                <div class="service-icon-wrap" style="background: var(--primary); color: #ffffff;">
                    <i class="fa-solid fa-stamp"></i>
                </div>
                <div class="service-tile-name">ศูนย์อนุมัติคำร้อง</div>
                <span class="service-tile-badge" style="background: #fee2e2; color: #dc2626; font-weight: 700;">
                    <i class="fa-solid fa-bolt me-1"></i>รอ <?= $pending_approvals ?>
                </span>
            </a>

        </section>

        <!-- FLOATING ACTION BUTTON (Quick Add) -->
        <a href="worklog_form.php" class="prototype-fab" title="เขียนบันทึกงานใหม่">
            <i class="fa-solid fa-plus"></i>
        </a>

        <!-- FLOATING BOTTOM DOCK NAVIGATION -->
        <nav class="bottom-dock">
            <a href="prototype.php" class="dock-item active">
                <i class="fa-solid fa-house"></i>
                <span>หน้าหลัก</span>
            </a>
            <a href="worklog.php" class="dock-item">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>บันทึกงาน</span>
            </a>
            <a href="approvals.php" class="dock-item">
                <i class="fa-solid fa-inbox"></i>
                <span>ศูนย์อนุมัติ</span>
                <span class="dock-badge"><?= $pending_approvals ?></span>
            </a>
            <a href="settings.php" class="dock-item">
                <i class="fa-solid fa-sliders"></i>
                <span>ตั้งค่า</span>
            </a>
        </nav>

    </div>

    <!-- Interactive Filtering Script -->
    <script>
        function filterServices(category, btn) {
            // Update active pill button
            document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Filter service tiles with smooth fade
            const tiles = document.querySelectorAll('.service-tile');
            tiles.forEach(tile => {
                const itemCat = tile.getAttribute('data-cat');
                if (category === 'all' || itemCat === category) {
                    tile.style.display = 'flex';
                    tile.style.opacity = '0';
                    tile.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        tile.style.opacity = '1';
                        tile.style.transform = 'scale(1)';
                    }, 50);
                } else {
                    tile.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
