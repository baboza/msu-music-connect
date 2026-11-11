<?php
// modules/qrcode/r.php - QR Code Tracking Redirector
require_once '../../config/db.php';

$code_key = trim($_GET['c'] ?? '');
$qr_id = intval($_GET['id'] ?? 0);

if (empty($code_key) && empty($qr_id)) {
    header("Location: ../../index.php");
    exit();
}

try {
    if (!empty($code_key)) {
        $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE code_key = ? LIMIT 1");
        $stmt->execute([$code_key]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE id = ? LIMIT 1");
        $stmt->execute([$qr_id]);
    }
    
    $qr = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($qr) {
        // Increment scan count and update last scan time
        $updateStmt = $pdo->prepare("UPDATE qr_codes SET scan_count = scan_count + 1, last_scanned_at = NOW() WHERE id = ?");
        $updateStmt->execute([$qr['id']]);

        $target = $qr['target_url'];
        // Ensure URL starts with http:// or https://
        if (!preg_match("~^(?:f|ht)tps?://~i", $target)) {
            $target = "https://" . $target;
        }

        header("Location: " . $target, true, 302);
        exit();
    }
} catch (PDOException $e) {
    // Fail gracefully
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ไม่พบลิงก์ QR Code - MSU Music Connect</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Anuphan', sans-serif; background-color: #f8f9fa; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
    <div class="card border-0 shadow-sm rounded-4 p-5 text-center" style="max-width: 460px;">
        <div class="text-secondary mb-3"><i class="fa-solid fa-qrcode fa-3x text-muted"></i></div>
        <h4 class="fw-bold text-dark mb-2">ไม่พบข้อมูล QR Code</h4>
        <p class="text-muted small mb-4">ลิงก์นี้อาจถูกยกเลิกหรือไม่มีอยู่ในระบบ MSU Music Connect</p>
        <a href="../../index.php" class="btn btn-primary rounded-pill px-4">กลับหน้าหลัก</a>
    </div>
</body>
</html>
