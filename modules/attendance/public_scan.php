<?php
// public_scan.php
// จุดบันทึกเวลาส่วนกลาง (Kiosk): ผู้ใช้เลือกชื่อตัวเอง หรือกดจากรายการ/ค้นหา แล้วถ่ายรูปใบหน้าพร้อมบันทึก Timestamp ทันที (ไม่ต้องใช้ QR Code)
require_once '../../config/db.php';
date_default_timezone_set('Asia/Bangkok');

// อัปเกรดคอลัมน์ user_id ใน attendance_logs ให้เป็น VARCHAR(50) อัตโนมัติหากยังเป็น int เดิม
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM attendance_logs LIKE 'user_id'");
    $colInfo = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : null;
    if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
        $uColStmt = $pdo->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_id' LIMIT 1");
        $uCol = $uColStmt ? $uColStmt->fetch(PDO::FETCH_ASSOC) : null;
        $charsetClause = (!empty($uCol['CHARACTER_SET_NAME']) && !empty($uCol['COLLATION_NAME']))
            ? ("CHARACTER SET " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['CHARACTER_SET_NAME']) . " COLLATE " . preg_replace('/[^a-zA-Z0-9_]/', '', $uCol['COLLATION_NAME']))
            : "";
        $pdo->exec("ALTER TABLE attendance_logs MODIFY COLUMN user_id VARCHAR(50) {$charsetClause} NOT NULL");
        $pdo->exec("
            UPDATE attendance_logs
            SET user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(photo_path, '_', 2), '_', -1)
            WHERE photo_path LIKE 'scan\\_%\\_%'
              AND EXISTS (
                  SELECT 1 FROM users u
                  WHERE u.user_id = SUBSTRING_INDEX(SUBSTRING_INDEX(attendance_logs.photo_path, '_', 2), '_', -1)
              )
        ");
    }
} catch (PDOException $e) {}

// ดึงรายชื่อเฉพาะบุคลากรสายสนับสนุนที่ Active
try {
    $stmt_users = $pdo->query("
        SELECT DISTINCT u.user_id, u.prefix_name, u.first_name, u.last_name, u.position, u.profile_picture 
        FROM users u 
        INNER JOIN user_roles ur ON u.user_id = ur.user_id 
        INNER JOIN roles r ON ur.role_id = r.role_id 
        WHERE u.status = 'active' 
          AND (r.role_id = 11 OR r.role_name = 'บุคลากรสายสนับสนุน')
        ORDER BY u.first_name ASC
    ");
    $users_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users_list = [];
}

// ดึงสถานะการลงเวลาของวันนี้ เพื่อแสดงสถานะและปิดปุ่มที่ลงเวลาไปแล้ว
$today_status = [];
try {
    $stmt_td = $pdo->query("SELECT user_id, scan_type, TIME_FORMAT(scan_time, '%H:%i') AS time_hm FROM attendance_logs WHERE DATE(scan_time) = CURDATE() ORDER BY scan_time ASC");
    while ($row_td = $stmt_td->fetch(PDO::FETCH_ASSOC)) {
        $uid_td = (string)$row_td['user_id'];
        if ($row_td['scan_type'] === 'in' && empty($today_status[$uid_td]['in'])) {
            $today_status[$uid_td]['in'] = $row_td['time_hm'];
        } elseif ($row_td['scan_type'] === 'out') {
            $today_status[$uid_td]['out'] = $row_td['time_hm'];
        }
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จุดถ่ายรูปบันทึกเวลาเข้า-ออกงาน (Attendance Kiosk) - MSU Music Connect</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

    <style>
        body { 
            font-family: 'Anuphan', sans-serif; 
            background: linear-gradient(135deg, #2b0052 0%, #17002e 100%); 
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 1.5rem;
            overflow-x: hidden;
        }
        
        .bg-shape {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(138,43,226,0.18) 0%, rgba(75,0,130,0) 70%);
            z-index: 0;
            pointer-events: none;
        }
        .shape-1 { width: 800px; height: 800px; top: -200px; left: -200px; }
        .shape-2 { width: 600px; height: 600px; bottom: -100px; right: -100px; }

        .main-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 32px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.45);
            overflow: hidden;
            width: 100%;
            max-width: 1240px;
            z-index: 10;
            display: flex;
            flex-direction: row;
            min-height: 640px;
        }

        .left-panel {
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 3.5rem;
            flex: 1.1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid rgba(0,0,0,0.06);
        }

        .right-panel {
            padding: 3.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            background-color: #ffffff;
        }

        .brand-text { color: #4b0082; font-weight: 800; font-size: 1.9rem; letter-spacing: -0.5px; }
        
        .clock-wrapper { margin: 2rem 0; }
        .clock { 
            font-size: 4.5rem; 
            font-weight: 800; 
            color: #1e293b; 
            line-height: 1; 
            margin-bottom: 0.5rem; 
            font-variant-numeric: tabular-nums;
            letter-spacing: -1px;
        }
        .date-text { font-size: 1.35rem; color: #64748b; font-weight: 500; }

        .camera-container {
            width: 100%;
            max-width: 440px;
            aspect-ratio: 4/3;
            background-color: #0f172a;
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 15px 35px rgba(75, 0, 130, 0.12);
            border: 3px solid #e2e8f0;
        }
        
        #kioskVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
        }

        .face-oval-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 2px dashed rgba(255,255,255,0.45);
            border-radius: 50%;
            width: 180px;
            height: 220px;
            pointer-events: none;
        }

        .live-timestamp-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 10px 16px;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            color: white;
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: none;
        }
        
        #resultOverlay { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(255, 255, 255, 0.96); z-index: 50; 
            display: none; flex-direction: column; align-items: center; justify-content: center;
            border-radius: 0 32px 32px 0; padding: 3rem; text-align: center;
            backdrop-filter: blur(10px);
        }
        .user-avatar { 
            width: 130px; height: 130px; border-radius: 50%; object-fit: cover; 
            border: 5px solid white; box-shadow: 0 15px 30px rgba(0,0,0,0.15); 
            margin-bottom: 1.25rem; 
        }
        .success-icon {
            position: absolute;
            bottom: -5px; right: 5px;
            width: 42px; height: 42px;
            background: #10b981; color: white;
            border-radius: 50%; border: 4px solid white;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }

        @media (max-width: 991px) {
            .main-card { flex-direction: column; border-radius: 24px; min-height: auto; }
            .left-panel, .right-panel { padding: 2rem; border-right: none; }
            .clock { font-size: 3.2rem; }
            .shape-1, .shape-2 { display: none; }
            #resultOverlay { border-radius: 24px; }
        }
    </style>
</head>
<body>

<div class="bg-shape shape-1"></div>
<div class="bg-shape shape-2"></div>

<div class="main-card animate__animated animate__fadeInUp">
    <!-- Left Panel: Info, Clock, User Selection -->
    <div class="left-panel">
        <div>
            <div class="d-flex align-items-center mb-2">
                <img src="../../uploads/logo.png" alt="Logo" height="50" class="me-3" onerror="this.style.display='none'">
                <div>
                    <h1 class="brand-text mb-0">MSU Music Connect</h1>
                    <p class="text-muted mb-0 fw-bold small">จุดถ่ายรูปบันทึกเวลาปฏิบัติงาน (Attendance Kiosk)</p>
                </div>
            </div>

            <div class="clock-wrapper">
                <div class="clock" id="clockDisplay">00:00:00</div>
                <div class="date-text" id="dateDisplay">Loading...</div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-4 shadow-sm border border-light">
            <label class="form-label fw-bold text-dark mb-2">
                <i class="fa-solid fa-user-check text-primary me-2"></i>เลือกชื่อบุคลากรสายสนับสนุนเพื่อลงเวลา
            </label>
            <select id="userSelect" class="form-select form-select-lg border-2 border-primary fw-bold text-dark mb-2 rounded-pill shadow-sm">
                <option value="">-- กรุณาคลิกเลือกชื่อของคุณ (บุคลากรสายสนับสนุน) --</option>
                <?php foreach ($users_list as $u): 
                    $uid_str = (string)$u['user_id'];
                    $in_hm = $today_status[$uid_str]['in'] ?? '';
                    $out_hm = $today_status[$uid_str]['out'] ?? '';
                    $base_label = $u['prefix_name'].$u['first_name'].' '.$u['last_name'] . (!empty($u['position']) ? ' ('.$u['position'].')' : '');
                    $status_suffix = '';
                    if ($in_hm && $out_hm) {
                        $status_suffix = " [เข้า {$in_hm} | ออก {$out_hm}]";
                    } elseif ($in_hm) {
                        $status_suffix = " [เข้าแล้ว {$in_hm}]";
                    } elseif ($out_hm) {
                        $status_suffix = " [ออกแล้ว {$out_hm}]";
                    }
                ?>
                    <option value="<?php echo htmlspecialchars($uid_str); ?>" 
                            data-name="<?php echo htmlspecialchars($u['prefix_name'].$u['first_name'].' '.$u['last_name']); ?>"
                            data-base-label="<?php echo htmlspecialchars($base_label); ?>"
                            data-in="<?php echo htmlspecialchars($in_hm); ?>"
                            data-out="<?php echo htmlspecialchars($out_hm); ?>"
                            data-avatar="<?php echo htmlspecialchars($u['profile_picture'] ?? ''); ?>">
                        <?php echo htmlspecialchars($base_label . $status_suffix); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div id="userTodayStatusBox" class="small mb-3 d-none"></div>

            <div class="d-flex gap-2">
                <button type="button" id="btnScanIn" class="btn btn-success btn-lg rounded-pill fw-bold flex-grow-1 shadow-sm py-2" onclick="handleKioskCapture('in')">
                    <i class="fa-solid fa-arrow-right-to-bracket me-2"></i> ถ่ายรูปเข้างาน
                </button>
                <button type="button" id="btnScanOut" class="btn btn-warning btn-lg text-dark rounded-pill fw-bold flex-grow-1 shadow-sm py-2" onclick="handleKioskCapture('out')">
                    <i class="fa-solid fa-arrow-right-from-bracket me-2"></i> ถ่ายรูปออกงาน
                </button>
            </div>
        </div>
    </div>

    <!-- Right Panel: Camera & Live Preview -->
    <div class="right-panel">
        <div class="text-center mb-3">
            <h4 class="fw-bold text-dark mb-1">กล้องบันทึกภาพและ Timestamp</h4>
            <p class="text-muted small mb-0">หันหน้าตรงมองกล้อง เลือกชื่อทางซ้าย แล้วกดปุ่มลงเวลา</p>
        </div>

        <div class="camera-container">
            <video id="kioskVideo" autoplay playsinline muted></video>
            <canvas id="kioskCanvas" style="display: none;"></canvas>
            <div class="face-oval-guide"></div>

            <div class="live-timestamp-bar">
                <span id="kioskOverlayUser" class="fw-bold text-truncate" style="max-width: 220px;">กรุณาเลือกชื่อ</span>
                <span id="kioskOverlayTime" class="extra-small opacity-75">--:--:--</span>
            </div>

            <!-- Loading Spinner Overlay -->
            <div id="kioskLoading" class="position-absolute top-0 start-0 w-100 h-100 bg-dark bg-opacity-75 d-flex flex-column align-items-center justify-content-center text-white d-none" style="z-index: 20;">
                <div class="spinner-border text-light mb-3" role="status"></div>
                <h6 class="fw-bold mb-0">กำลังบันทึกภาพและเวลา...</h6>
            </div>
        </div>

        <div id="kioskAlert" class="alert alert-danger border-0 rounded-pill small mt-3 py-2 px-4 shadow-sm d-none"></div>
        
        <!-- Result Overlay -->
        <div id="resultOverlay" class="animate__animated">
            <div class="position-relative d-inline-block mb-3">
                <img src="" id="resAvatar" class="user-avatar d-none">
                <div id="resIcon" class="success-icon d-none"><i class="fa-solid fa-check"></i></div>
            </div>
            <h2 class="fw-bold text-dark mb-2" id="resName">ชื่อ-นามสกุล</h2>
            <div class="fs-4 mb-3 fw-bold" id="resStatus">สถานะ</div>
            <div class="text-muted bg-light px-4 py-2 rounded-pill d-inline-block border">
                เวลา <span id="resTime" class="fw-bold text-dark"></span> น.
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const kioskLoadDate = <?php echo json_encode(date('Y-m-d')); ?>;

    // Live Clock + รีเซ็ตอัตโนมัติเมื่อข้ามเที่ยงคืน (กรณีเปิดจอ Kiosk ทิ้งไว้ข้ามวัน)
    function updateClock() {
        const now = new Date();
        const localYmd = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        if (localYmd !== kioskLoadDate && !isProcessing) {
            window.location.reload();
            return;
        }
        const timeStr = now.toLocaleTimeString('th-TH', { hour12: false });
        const dateStr = now.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        $('#clockDisplay').text(timeStr);
        $('#dateDisplay').text(dateStr);
        $('#kioskOverlayTime').text(timeStr + ' น.');
    }
    setInterval(updateClock, 1000);
    updateClock();

    function refreshSelectedUserButtons() {
        const selected = $('#userSelect').find('option:selected');
        const uid = selected.val();
        const inTime = selected.attr('data-in') || '';
        const outTime = selected.attr('data-out') || '';
        const statusBox = $('#userTodayStatusBox');
        const btnIn = $('#btnScanIn');
        const btnOut = $('#btnScanOut');

        if (!uid) {
            statusBox.addClass('d-none').empty();
            btnIn.prop('disabled', false).html('<i class="fa-solid fa-arrow-right-to-bracket me-2"></i> ถ่ายรูปเข้างาน');
            btnOut.prop('disabled', false).html('<i class="fa-solid fa-arrow-right-from-bracket me-2"></i> ถ่ายรูปออกงาน');
            return;
        }

        if (inTime) {
            btnIn.prop('disabled', true).html('<i class="fa-solid fa-circle-check me-2"></i> เข้างานแล้ว (' + inTime + ' น.)');
        } else {
            btnIn.prop('disabled', false).html('<i class="fa-solid fa-arrow-right-to-bracket me-2"></i> ถ่ายรูปเข้างาน');
        }

        if (outTime) {
            btnOut.prop('disabled', true).html('<i class="fa-solid fa-circle-check me-2"></i> ออกงานแล้ว (' + outTime + ' น.)');
        } else {
            btnOut.prop('disabled', false).html('<i class="fa-solid fa-arrow-right-from-bracket me-2"></i> ถ่ายรูปออกงาน');
        }

        if (inTime && outTime) {
            statusBox.removeClass('d-none').html('<span class="badge bg-secondary-subtle text-dark border px-3 py-2 rounded-pill w-100"><i class="fa-solid fa-circle-check text-success me-1"></i> วันนี้ลงเวลาครบแล้ว: เข้างาน <strong>' + inTime + ' น.</strong> | ออกงาน <strong>' + outTime + ' น.</strong></span>');
        } else if (inTime) {
            statusBox.removeClass('d-none').html('<span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill w-100"><i class="fa-solid fa-clock me-1"></i> วันนี้ลงเวลาเข้างานแล้วเมื่อ <strong>' + inTime + ' น.</strong> (กดปุ่มออกงานได้เมื่อเลิกงาน)</span>');
        } else if (outTime) {
            statusBox.removeClass('d-none').html('<span class="badge bg-warning-subtle text-dark border border-warning-subtle px-3 py-2 rounded-pill w-100"><i class="fa-solid fa-clock me-1"></i> วันนี้ลงเวลาออกงานแล้วเมื่อ <strong>' + outTime + ' น.</strong></span>');
        } else {
            statusBox.addClass('d-none').empty();
        }
    }

    function updateOptionStatusAttr(uid, scanType, hmStr) {
        const opt = $('#userSelect option').filter(function() { return $(this).val() === String(uid); });
        if (!opt.length) return;
        if (scanType === 'in') opt.attr('data-in', hmStr);
        if (scanType === 'out') opt.attr('data-out', hmStr);
        const inHm = opt.attr('data-in') || '';
        const outHm = opt.attr('data-out') || '';
        const baseLabel = opt.attr('data-base-label') || opt.text();
        let suffix = '';
        if (inHm && outHm) suffix = ' [เข้า ' + inHm + ' | ออก ' + outHm + ']';
        else if (inHm) suffix = ' [เข้าแล้ว ' + inHm + ']';
        else if (outHm) suffix = ' [ออกแล้ว ' + outHm + ']';
        opt.text(baseLabel + suffix);
    }

    // User selection change
    $('#userSelect').on('change', function() {
        const selected = $(this).find('option:selected');
        const name = selected.data('name');
        $('#kioskOverlayUser').text(name ? name : 'กรุณาเลือกชื่อ');
        $('#kioskAlert').addClass('d-none');
        refreshSelectedUserButtons();
    });

    // Camera setup
    const video = document.getElementById('kioskVideo');
    const canvas = document.getElementById('kioskCanvas');
    let stream = null;

    function initCamera() {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false
            })
            .then(s => {
                stream = s;
                video.srcObject = s;
            })
            .catch(err => {
                console.error("Camera error: ", err);
                $('#kioskAlert').text('ไม่สามารถเปิดกล้องได้ กรุณาอนุญาตใช้งานกล้องบนเบราว์เซอร์').removeClass('d-none');
            });
        } else {
            $('#kioskAlert').text('เบราว์เซอร์ไม่รองรับกล้องเว็บแคม').removeClass('d-none');
        }
    }
    initCamera();

    let isProcessing = false;

    function handleKioskCapture(type) {
        if (isProcessing) return;

        const userId = $('#userSelect').val();
        if (!userId) {
            $('#kioskAlert').text('กรุณาเลือกชื่อบุคลากรของท่านก่อนกดลงเวลา').removeClass('d-none');
            $('#userSelect').focus();
            return;
        }

        if (!video || !video.videoWidth) {
            $('#kioskAlert').text('กล้องยังไม่พร้อมใช้งาน กรุณารอสักครู่').removeClass('d-none');
            return;
        }

        isProcessing = true;
        $('#kioskAlert').addClass('d-none');
        $('#kioskLoading').removeClass('d-none');

        // Capture photo
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');

        // Mirror horizontal image
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        ctx.setTransform(1, 0, 0, 1, 0, 0);

        // Watermark Timestamp & Name
        const userName = $('#userSelect').find('option:selected').data('name');
        const now = new Date();
        const dateStr = now.toLocaleDateString('th-TH') + ' ' + now.toLocaleTimeString('th-TH', { hour12: false });
        const typeText = (type === 'in') ? 'เข้างาน (CHECK-IN)' : 'ออกงาน (CHECK-OUT)';

        ctx.fillStyle = "rgba(0, 0, 0, 0.6)";
        ctx.fillRect(0, canvas.height - 45, canvas.width, 45);

        ctx.font = "bold 15px sans-serif";
        ctx.fillStyle = "#ffffff";
        ctx.fillText(userName + " | " + typeText + " | " + dateStr, 15, canvas.height - 18);

        const photoData = canvas.toDataURL('image/jpeg', 0.85);

        // Send to server
        $.ajax({
            url: 'process_scan.php',
            type: 'POST',
            data: {
                user_id: userId,
                scan_type: type,
                photo: photoData
            },
            dataType: 'json',
            success: function(res) {
                $('#kioskLoading').addClass('d-none');
                const overlay = $('#resultOverlay');
                const statusEl = $('#resStatus');
                const iconEl = $('#resIcon');
                
                overlay.removeClass('animate__fadeOut').addClass('animate__fadeIn').css('display', 'flex');
                
                if (res.status === 'success') {
                    const isIn = res.scan_type === 'in';
                    const hmNow = res.scan_time ? res.scan_time.substring(11, 16) : new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false });
                    updateOptionStatusAttr(userId, res.scan_type, hmNow);

                    statusEl.html(isIn ? 'บันทึกเข้างานเรียบร้อย' : 'บันทึกออกงานเรียบร้อย');
                    statusEl.css('color', isIn ? '#10b981' : '#f59e0b');
                    
                    iconEl.removeClass('d-none').css('background', isIn ? '#10b981' : '#f59e0b')
                          .html(isIn ? '<i class="fa-solid fa-arrow-right-to-bracket"></i>' : '<i class="fa-solid fa-arrow-right-from-bracket"></i>');
                } else if (res.status === 'duplicate') {
                    if (res.scan_type && res.recorded_time) {
                        updateOptionStatusAttr(userId, res.scan_type, res.recorded_time);
                    }
                    statusEl.html(res.message);
                    statusEl.css('color', '#d97706');
                    iconEl.removeClass('d-none').css('background', '#f59e0b').html('<i class="fa-solid fa-clock"></i>');
                } else {
                    statusEl.html(res.message);
                    statusEl.css('color', '#ef4444');
                    iconEl.removeClass('d-none').css('background', '#ef4444').html('<i class="fa-solid fa-xmark"></i>');
                }

                if (res.user_name) {
                    $('#resName').text(res.user_name).show();
                } else {
                    $('#resName').text(userName).show();
                }

                if (res.avatar) {
                    const avatarSrc = /^https?:\/\//i.test(res.avatar) ? res.avatar : ('../../uploads/avatars/' + res.avatar);
                    $('#resAvatar')
                        .off('error')
                        .on('error', function() { $(this).addClass('d-none'); })
                        .attr('src', avatarSrc)
                        .removeClass('d-none');
                } else {
                    $('#resAvatar').addClass('d-none');
                }
                
                $('#resTime').text(res.scan_time ? res.scan_time.substring(11, 19) : new Date().toLocaleTimeString('th-TH', { hour12: false }));

                // Reset after 3.5 seconds
                setTimeout(() => {
                    overlay.removeClass('animate__fadeIn').addClass('animate__fadeOut');
                    setTimeout(() => {
                        overlay.hide();
                        isProcessing = false;
                        $('#userSelect').val('');
                        $('#kioskOverlayUser').text('กรุณาเลือกชื่อ');
                        refreshSelectedUserButtons();
                    }, 400);
                }, 3500);
            },
            error: function(err) {
                $('#kioskLoading').addClass('d-none');
                isProcessing = false;
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์ กรุณาลองใหม่อีกครั้ง');
            }
        });
    }
</script>
</body>
</html>
