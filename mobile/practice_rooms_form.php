<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// ดึงรายชื่อห้องซ้อมที่เปิดให้ใช้งาน
$stmt = $pdo->query("SELECT room_id, room_type, capacity FROM practice_rooms WHERE is_active = 1 ORDER BY room_id");
$rooms = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = trim($_POST['room_id']);
    $booking_date = trim($_POST['booking_date']);
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $purpose = trim($_POST['purpose']);
    
    // สร้างรหัสการจอง
    $booking_code = 'PB' . date('Ymd') . rand(1000, 9999);

    if (empty($room_id) || empty($booking_date) || empty($start_time) || empty($end_time)) {
        $_SESSION['error'] = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } elseif ($start_time >= $end_time) {
        $_SESSION['error'] = "เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น";
    } else {
        try {
            // เช็คว่ามีการจองทับซ้อนหรือไม่
            $stmt_check = $pdo->prepare("
                SELECT COUNT(*) FROM practice_bookings 
                WHERE room_id = ? AND booking_date = ? AND status IN ('pending', 'approved', 'active')
                AND ((start_time < ? AND end_time > ?) OR (start_time >= ? AND start_time < ?))
            ");
            $stmt_check->execute([$room_id, $booking_date, $end_time, $start_time, $start_time, $end_time]);
            $overlap = $stmt_check->fetchColumn();

            if ($overlap > 0) {
                $_SESSION['error'] = "ห้องซ้อมนี้มีการจองในช่วงเวลาดังกล่าวแล้ว โปรดเลือกเวลาอื่น";
            } else {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO practice_bookings (booking_code, student_id, room_id, booking_date, start_time, end_time, purpose, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt_insert->execute([$booking_code, $user_id, $room_id, $booking_date, $start_time, $end_time, $purpose]);
                
                $_SESSION['success'] = "บันทึกการจองห้องซ้อมเรียบร้อยแล้ว รอการอนุมัติ";
                header("Location: practice_rooms.php");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}
?>

<div class="mt-2 mb-4">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="practice_rooms.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าจองห้องซ้อม">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold text-dark mb-0">ฟอร์มจองห้องซ้อมดนตรี</h5>
            <small class="text-muted">กรอกรายละเอียดเพื่อขอใช้ห้องฝึกซ้อม</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-music"></i>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-3" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Live Map Container -->
    <div class="bento-box p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-map-location-dot me-2 text-purple"></i>ผังห้องซ้อม (Real-time)</h6>
            <span class="badge bg-success shadow-sm pulse-badge" id="mapRefreshStatus"><i class="fa-solid fa-circle-check me-1"></i>Live</span>
        </div>
        <div id="liveMapContainer" class="p-2 bg-light rounded-3">
            <div class="text-center text-muted py-3 small"><i class="fa-solid fa-spinner fa-spin me-2"></i>กำลังโหลดแผนผัง...</div>
        </div>
    </div>

    <div class="bento-box p-4 mb-4">
        <form action="practice_rooms_form.php" method="POST">
            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">เลือกห้องซ้อม <span class="text-danger">*</span></label>
                <select name="room_id" class="form-select form-select-sm" required>
                    <option value="">-- เลือกห้องซ้อม --</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= htmlspecialchars($r['room_id']) ?>">
                            ห้อง <?= htmlspecialchars($r['room_id']) ?> (<?= htmlspecialchars($r['room_type']) ?>) - จุ <?= $r['capacity'] ?> คน
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label small text-muted mb-1 fw-bold">วันที่ใช้งาน <span class="text-danger">*</span></label>
                <input type="date" name="booking_date" class="form-control form-control-sm" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>

            <!-- Schedule Timeline Placeholder -->
            <div class="mb-3" id="scheduleContainer" style="display: none;">
                <label class="form-label small text-muted mb-1 fw-bold"><i class="fa-solid fa-list-check me-1 text-purple"></i>รายการจองในวันที่เลือก</label>
                <div class="bg-light p-2 rounded-3 border" id="scheduleList">
                    <!-- Fetched dynamically -->
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">เวลาเริ่ม <span class="text-danger">*</span></label>
                    <input type="time" name="start_time" class="form-control form-control-sm" required>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1 fw-bold">เวลาสิ้นสุด <span class="text-danger">*</span></label>
                    <input type="time" name="end_time" class="form-control form-control-sm" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small text-muted mb-1 fw-bold">วัตถุประสงค์ (ถ้ามี)</label>
                <textarea name="purpose" class="form-control form-control-sm" rows="3" placeholder="ระบุเหตุผลในการใช้งาน..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
                <i class="fa-solid fa-paper-plane me-2"></i> ยืนยันการจองห้องซ้อม
            </button>
        </form>
    </div>

    <div style="height: 60px;"></div>
</div>

<style>
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
.pulse-badge { animation: pulse 2s infinite; }
</style>

<script>
let mapInterval;

function initPracticePage() {
    const roomSelect = document.querySelector('select[name="room_id"]');
    const dateInput = document.querySelector('input[name="booking_date"]');
    const scheduleContainer = document.getElementById('scheduleContainer');
    const scheduleList = document.getElementById('scheduleList');
    const mapContainer = document.getElementById('liveMapContainer');
    
    if(!roomSelect || !dateInput) return;

    // Fetch Live Map
    const loadMap = () => {
        if(!mapContainer) return;
        fetch('api_practice_map.php')
            .then(res => res.text())
            .then(html => {
                mapContainer.innerHTML = html;
            })
            .catch(err => console.error(err));
    };

    // Auto Refresh Map every 15 seconds
    loadMap();
    clearInterval(mapInterval);
    mapInterval = setInterval(loadMap, 15000);

    // Fetch Schedule
    const loadSchedule = () => {
        const roomId = roomSelect.value;
        const date = dateInput.value;
        if(!roomId || !date) {
            scheduleContainer.style.display = 'none';
            return;
        }

        scheduleContainer.style.display = 'block';
        scheduleList.innerHTML = '<div class="text-center text-muted small"><i class="fa-solid fa-spinner fa-spin me-2"></i>กำลังโหลด...</div>';

        fetch(`api_practice_schedule.php?room_id=${encodeURIComponent(roomId)}&date=${date}`)
            .then(res => res.json())
            .then(data => {
                if(data.error) {
                    scheduleList.innerHTML = `<div class="text-danger small"><i class="fa-solid fa-circle-exclamation me-1"></i>${data.error}</div>`;
                    return;
                }
                if(data.length === 0) {
                    scheduleList.innerHTML = '<div class="text-success small fw-bold"><i class="fa-solid fa-circle-check me-1"></i>ว่างตลอดทั้งวัน</div>';
                } else {
                    let html = '<div class="d-flex flex-wrap gap-1">';
                    data.forEach(item => {
                        const start = item.start_time.substring(0, 5);
                        const end = item.end_time.substring(0, 5);
                        html += `<span class="badge bg-danger-subtle text-danger border rounded-pill py-1 px-2" style="font-size:0.7rem;"><i class="fa-regular fa-clock me-1"></i>${start}-${end}</span>`;
                    });
                    html += '</div>';
                    scheduleList.innerHTML = html;
                }
            })
            .catch(err => {
                scheduleList.innerHTML = '<div class="text-danger small">โหลดข้อมูลไม่สำเร็จ</div>';
            });
    };

    roomSelect.addEventListener('change', loadSchedule);
    dateInput.addEventListener('change', loadSchedule);
    
    if(roomSelect.value && dateInput.value) {
        loadSchedule();
    }
}

// Initial call
initPracticePage();

// If navigated via HTMX
document.addEventListener('htmx:afterSwap', function(e) {
    if(e.detail.target.id === 'liveMapContainer') return;
    initPracticePage();
});
</script>

<?php require_once 'includes/footer.php'; ?>
