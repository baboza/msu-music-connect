<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$pr_approver_roles = ['admin', 'งานโสตทัศนศึกษา', 'ฝ่ายโสต', 'ฝ่ายโสตทัศนูปกรณ์', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($pr_approver_roles)) {
    echo "<script>alert('⛔ ปฏิเสธการเข้าถึง'); window.location.href='index.php';</script>";
    exit();
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'ticker_text_th' => trim($_POST['ticker_text_th'] ?? ''),
        'ticker_text_en' => trim($_POST['ticker_text_en'] ?? ''),
        'ticker_text_cn' => trim($_POST['ticker_text_cn'] ?? ''),
        'kiosk_idle_timeout' => max(15, (int)($_POST['kiosk_idle_timeout'] ?? 60)),
        'default_slide_duration' => max(5, (int)($_POST['default_slide_duration'] ?? 10))
    ];

    $stmtUpdate = $pdo->prepare("
        INSERT INTO pr_display_settings (setting_key, setting_value) 
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = :v
    ");

    foreach ($settings as $k => $v) {
        $stmtUpdate->execute([':k' => $k, ':v' => $v]);
    }
    $msg = 'บันทึกการตั้งค่าจอแสดงผลเรียบร้อยแล้ว!';
}

// Fetch current settings
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM pr_display_settings");
$current = [];
while ($r = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
    $current[$r['setting_key']] = $r['setting_value'];
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<link rel="stylesheet" href="assets/css/pr_theme.css">

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 mb-5" style="background-color: #f8fafc; min-height: 100vh;">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="d-flex align-items-center gap-2 mb-4">
                <a href="index.php" class="btn btn-sm btn-outline-purple rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> หน้าหลัก PR
                </a>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-gear text-purple me-2"></i>ตั้งค่าระบบจอแสดงผล (PR Display Settings)
                </h3>
            </div>

            <?php if ($msg): ?>
                <div class="alert badge-emerald-soft alert-dismissible fade show rounded-4 shadow-sm mb-4">
                    <i class="fa-solid fa-circle-check me-2 fs-5 text-success"></i> <?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="save_settings" value="1">

                <!-- 1. Marquee Ticker Settings -->
                <div class="card card-modern mb-4">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <i class="fa-solid fa-bullhorn text-purple"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">
                            ข้อความตัววิ่งด้านล่างจอที่ 1 (Ticker Bar - 3 ภาษา)
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">🇹🇭 ข้อความตัววิ่งภาษาไทย</label>
                            <input type="text" name="ticker_text_th" class="form-control rounded-3" value="<?= htmlspecialchars($current['ticker_text_th'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">🇬🇧 ข้อความตัววิ่งภาษาอังกฤษ</label>
                            <input type="text" name="ticker_text_en" class="form-control rounded-3" value="<?= htmlspecialchars($current['ticker_text_en'] ?? '') ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark">🇨🇳 ข้อความตัววิ่งภาษาจีน</label>
                            <input type="text" name="ticker_text_cn" class="form-control rounded-3" value="<?= htmlspecialchars($current['ticker_text_cn'] ?? '') ?>">
                        </div>
                        <div class="form-text text-muted extra-small">
                            * ข้อความทั้ง 3 ภาษาจะแสดงวนต่อกันที่แถบด้านล่างของจอที่ 1
                        </div>
                    </div>
                </div>

                <!-- 2. Screen 1 & Screen 2 Technical Parameters -->
                <div class="card card-modern mb-4">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-purple-subtle d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <i class="fa-solid fa-sliders text-purple"></i>
                        </div>
                        <h5 class="fw-bold text-dark mb-0">
                            การตั้งค่าการทำงานของหน้าจอ
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-stopwatch text-purple me-1"></i> เวลา Idle Timeout ของจอที่ 2 (Kiosk Touch Screen)
                            </label>
                            <div class="input-group">
                                <input type="number" name="kiosk_idle_timeout" class="form-control rounded-start-3" value="<?= htmlspecialchars($current['kiosk_idle_timeout'] ?? 60) ?>" min="15" max="300" required>
                                <span class="input-group-text rounded-end-3 bg-white text-muted">วินาที</span>
                            </div>
                            <div class="form-text text-muted extra-small">
                                หากไม่มีผู้ใช้งานแตะหน้าจอเกินเวลาที่กำหนด จอจะรีเซ็ตกลับสู่หน้าแรกและเปลี่ยนภาษาเป็นภาษาไทยอัตโนมัติ (ปัจจุบันตั้งไว้: 60 วินาที / 1 นาที)
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark">
                                <i class="fa-solid fa-play text-purple me-1"></i> เวลาแสดงสไลด์เริ่มต้นของจอที่ 1 (Default Slide Duration)
                            </label>
                            <div class="input-group">
                                <input type="number" name="default_slide_duration" class="form-control rounded-start-3" value="<?= htmlspecialchars($current['default_slide_duration'] ?? 10) ?>" min="5" max="60" required>
                                <span class="input-group-text rounded-end-3 bg-white text-muted">วินาที</span>
                            </div>
                            <div class="form-text text-muted extra-small">
                                เวลาแสดงภาพนิ่งต่อภาพ หากไม่ได้ระบุแยกในตอนส่งป้าย (แนะนำ: 10 - 15 วินาที)
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="text-end">
                    <button type="submit" class="btn btn-purple rounded-pill px-5 py-2 shadow-sm">
                        <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกการตั้งค่าทั้งหมด
                    </button>
                </div>

            </form>

        </div>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>
