<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$is_home_page = in_array($current_page, ['index.php', 'mobile', '']) || in_array($current_script, ['index.php']);

// คำนวณยอดรออนุมัติเฉพาะรายการที่ผู้ใช้มีสิทธิ์พิจารณา (ตาม requests_list.php)
$mobile_pending_badge = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/approval_functions.php';
    $u_roles = $_SESSION['roles'] ?? [];
    if (empty($u_roles) && isset($_SESSION['role'])) {
        $u_roles = [$_SESSION['role']];
    }
    $badge_counts = getMobileApprovalCounts($pdo, $_SESSION['user_id'], $u_roles);
    $mobile_pending_badge = $badge_counts['total'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#24005e">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="manifest.json">
    <title>Music MSU Connect - Enterprise Mobile</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- App Style -->
    <link rel="stylesheet" href="css/app-style.css">
    <link rel="stylesheet" href="css/dark-mode.css">
    
    <!-- HTMX for smooth transitions -->
    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
    <script>
        if (localStorage.getItem("theme") === "dark") {
            document.documentElement.classList.add("dark-mode");
        }

        // Global User Dropdown Menu Controller (Works seamlessly across direct visits & HTMX swaps)
        function toggleUserDropdown(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var menu = document.getElementById("mobileUserDropdownMenu");
            if (menu) {
                menu.classList.toggle("show");
            }
        }

        // Global dismiss on click outside
        document.addEventListener("click", function(e) {
            var menu = document.getElementById("mobileUserDropdownMenu");
            var btn = document.getElementById("mobileUserMenuBtn");
            if (menu && menu.classList.contains("show")) {
                if (!menu.contains(e.target) && (!btn || !btn.contains(e.target))) {
                    menu.classList.remove("show");
                }
            }
        });
    </script>
    <style>
        body {
            padding-top: 0 !important;
        }
        .mobile-header-spacer {
            height: calc(56px + env(safe-area-inset-top));
            width: 100%;
            flex-shrink: 0;
        }
        #mobileUserDropdownMenu {
            display: none;
            position: absolute;
            right: 0 !important;
            top: 100% !important;
            left: auto !important;
            margin-top: 8px !important;
        }
        #mobileUserDropdownMenu.show {
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
            animation: mobileDropdownFadeIn 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes mobileDropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-8px) scale(0.96);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
    </style>
</head>
<?php $is_home_page = ($current_page === 'index.php'); ?>
<body hx-boost="true" class="<?= $is_home_page ? 'page-home' : '' ?>">

<?php if (!$is_home_page): ?>
<!-- Mobile Enterprise Header (Fixed Top with Safe Area) for Subpages -->
<nav class="navbar fixed-top mobile-header">
    <div class="container-fluid d-flex justify-content-between align-items-center px-3 h-100">
        <a class="mobile-brand-link" href="index.php">
            <img src="../uploads/logo.png" alt="Logo" class="mobile-brand-logo">
            <div class="mobile-brand-title">
                <span>Music MSU Connect</span>
                <span class="mobile-brand-tag">ERP 2.0</span>
            </div>
        </a>
        
        <div class="d-flex align-items-center gap-2">
            <a href="approvals.php" class="mobile-icon-btn" title="ศูนย์อนุมัติคำร้อง">
                <i class="fa-solid fa-inbox text-purple" style="font-size: 0.95rem;"></i>
                <?php if ($mobile_pending_badge > 0): ?>
                    <span class="mobile-badge-dot"><?= $mobile_pending_badge > 99 ? '99+' : $mobile_pending_badge ?></span>
                <?php endif; ?>
            </a>
            
            <div class="dropdown" hx-disable style="position: relative;">
                <button type="button" class="btn p-0 border-0 bg-transparent shadow-none d-block position-relative" onclick="toggleUserDropdown(event)" id="mobileUserMenuBtn" aria-label="User Menu" style="outline: none; -webkit-tap-highlight-color: transparent;">
                    <?php if (!empty($current_avatar)): ?>
                        <?php $avatar_url = (strpos($current_avatar, 'http') === 0) ? $current_avatar : '../uploads/avatars/' . $current_avatar; ?>
                        <img src="<?= htmlspecialchars($avatar_url) ?>" alt="Profile" class="rounded-circle shadow-sm" width="36" height="36" style="border: 2px solid var(--primary); object-fit: cover;">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($full_name) ?>&background=39048f&color=fff" alt="Profile" class="rounded-circle shadow-sm" width="36" height="36" style="border: 2px solid var(--primary);">
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" id="mobileUserDropdownMenu" style="border-radius: 16px; min-width: 245px; border: 1px solid rgba(57, 4, 143, 0.12) !important; z-index: 1060; box-shadow: 0 14px 35px -4px rgba(15, 23, 42, 0.22) !important;">
                    <li class="px-3 py-2 bg-light rounded-3 mb-2 d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; min-width: 32px; background: var(--primary);">
                            <i class="fa-solid fa-user small"></i>
                        </div>
                        <div class="overflow-hidden">
                            <div class="fw-bold text-dark small text-truncate"><?= htmlspecialchars($full_name) ?></div>
                            <div class="text-muted extra-small text-truncate"><?= htmlspecialchars($_SESSION['roles'][0] ?? 'บุคลากร') ?></div>
                        </div>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 small fw-semibold" href="profile.php" hx-boost="true">
                            <i class="fa-solid fa-id-card me-2 text-purple"></i> ข้อมูลโปรไฟล์
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 small fw-semibold" href="settings.php" hx-boost="true">
                            <i class="fa-solid fa-sliders me-2 text-purple"></i> ตั้งค่าการใช้งาน
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 small fw-semibold text-primary" href="../index.php?pc=1" hx-disable>
                            <i class="fa-solid fa-desktop me-2 text-primary"></i> สลับไปเวอร์ชัน PC (Desktop)
                        </a>
                    </li>

                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 small fw-bold text-danger" href="../auth/logout.php?from=mobile" hx-disable>
                            <i class="fa-solid fa-power-off me-2 text-danger"></i> ออกจากระบบ (Logout)
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
<div class="mobile-header-spacer"></div>
<?php endif; ?>

<!-- Main Container Area -->
<div class="<?= $is_home_page ? 'container-fluid p-0 pb-4' : 'container px-3 pb-4' ?>">
