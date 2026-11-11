<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// ดึงข้อมูล Zoom Accounts
$stmt_acc = $pdo->query("SELECT * FROM zoom_accounts ORDER BY id ASC");
$accounts = $stmt_acc->fetchAll(PDO::FETCH_ASSOC);

// ดึงข้อมูลการจองที่กำลังจะเกิดขึ้น (เริ่มต้นตั้งแต่วันนี้เป็นต้นไป)
$stmt_bookings = $pdo->query("
    SELECT b.*, a.account_name, a.meeting_url, a.passcode, u.first_name, u.last_name 
    FROM zoom_bookings b
    JOIN zoom_accounts a ON b.zoom_account_id = a.id
    JOIN users u ON b.user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci
    WHERE b.end_time >= NOW()
    ORDER BY b.start_time ASC
");
$bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-4 pb-2 mb-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">
                    <i class="fa-solid fa-video me-2 text-primary"></i> 
                    ระบบจองใช้งาน Zoom (ส่วนกลาง)
                </h1>
                <p class="text-muted mb-0">ระบบจองสิทธิ์ใช้งานบัญชี Zoom ของคณะ แบบ First-come, First-served (ไม่ต้องรออนุมัติ)</p>
            </div>
            <div class="mt-2 mt-md-0 d-flex gap-2">
                <?php if(isset($_SESSION['roles']) && in_array('admin', $_SESSION['roles'])): ?>
                <a href="manage_accounts.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                    <i class="fa-solid fa-gear me-2"></i> จัดการบัญชี Zoom
                </a>
                <?php endif; ?>
                <a href="book.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="fa-solid fa-calendar-plus me-2"></i> จองเวลาใช้งาน
                </a>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['msg'])): 
        $alert_type = isset($_SESSION['msg_type']) && $_SESSION['msg_type'] == 'danger' ? 'danger' : 'success';
    ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <i class="fa-solid <?= $alert_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <strong><?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php foreach($accounts as $acc): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-body p-4 d-flex align-items-center gap-3">
                    <div class="bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-video fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($acc['account_name']) ?></h5>
                        <p class="text-muted small mb-0"><i class="fa-solid fa-envelope me-1"></i> <?= htmlspecialchars($acc['email']) ?></p>
                        <?php if(!empty($acc['meeting_id'])): ?>
                            <div class="small text-muted mt-1"><i class="fa-solid fa-hashtag me-1"></i> Meeting ID: <?= htmlspecialchars($acc['meeting_id']) ?></div>
                        <?php endif; ?>
                        <?php if(!empty($acc['meeting_url'])): ?>
                            <a href="<?= htmlspecialchars($acc['meeting_url']) ?>" target="_blank" class="small text-decoration-none mt-1 d-inline-block">
                                <i class="fa-solid fa-link"></i> ลิงก์ห้องประจำ
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold text-dark mb-0"><i class="fa-regular fa-calendar-lines me-2 text-primary"></i> ปฏิทินคิวจอง Zoom</h5>
        </div>
        <div class="card-body p-4 pt-3">
            <!-- FullCalendar Container -->
            <div id="calendar" style="min-height: 600px;"></div>
        </div>
    </div>
</main>

<!-- Event Details Modal -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="fw-bold text-dark"><i class="fa-solid fa-video text-primary me-2"></i>รายละเอียดการจอง Zoom</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="text-muted small fw-bold mb-1">หัวข้อการประชุม</label>
                    <div id="modalTitle" class="fw-bold text-dark fs-5"></div>
                </div>
                <div class="mb-3">
                    <label class="text-muted small fw-bold mb-1">บัญชี Zoom ที่ใช้</label>
                    <div id="modalAccount" class="text-primary fw-bold"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="text-muted small fw-bold mb-1">Meeting ID</label>
                        <div id="modalMeetingId" class="text-dark"></div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small fw-bold mb-1">Passcode</label>
                        <div id="modalPasscode" class="text-dark"></div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="text-muted small fw-bold mb-1">เวลาเริ่มต้น</label>
                        <div id="modalStart" class="text-dark"></div>
                    </div>
                    <div class="col-6">
                        <label class="text-muted small fw-bold mb-1">เวลาสิ้นสุด</label>
                        <div id="modalEnd" class="text-dark"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="text-muted small fw-bold mb-1">ผู้จอง</label>
                    <div id="modalUser" class="text-dark"></div>
                </div>
                
                <div class="mb-3">
                    <label class="text-muted small fw-bold mb-1">ลิงค์เข้าร่วมประชุม (Meeting URL)</label>
                    <div id="modalMeetingUrl" class="text-dark"></div>
                </div>
                
                <!-- QR Code Section -->
                <div id="modalQrContainer" class="text-center mt-4 d-none">
                    <label class="text-muted small fw-bold mb-2 d-block">QR Code สำหรับเข้าร่วมประชุม</label>
                    <div class="bg-white p-2 d-inline-block border rounded-3 shadow-sm mb-2">
                        <canvas id="qrCanvas"></canvas>
                    </div>
                    <div>
                        <a id="btnDownloadQr" href="#" download="zoom-meeting-qr.png" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm">
                            <i class="fa-solid fa-download me-1"></i> โหลด QR Code ไปใช้
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ปิด</button>
                <div id="modalActionContainer">
                    <!-- Delete button will be injected here if the user has permission -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FullCalendar Libraries -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/th.js'></script>
<script src='https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js'></script>

<!-- QR Code Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>

<?php require_once '../../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var eventModal = new bootstrap.Modal(document.getElementById('eventModal'));

    var calendar = new FullCalendar.Calendar(calendarEl, {
        locale: 'th',
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        themeSystem: 'bootstrap5',
        events: 'api_bookings.php',
        eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            meridiem: false,
            hour12: false
        },
        displayEventTime: true,
        displayEventEnd: true,
        eventClick: function(info) {
            var props = info.event.extendedProps;
            
            document.getElementById('modalTitle').textContent = props.booking_title;
            document.getElementById('modalAccount').textContent = props.account_name;
            document.getElementById('modalMeetingId').textContent = props.meeting_id || '-';
            document.getElementById('modalPasscode').textContent = props.passcode || '-';
            document.getElementById('modalUser').innerHTML = '<i class="fa-solid fa-user me-1 text-muted"></i> ' + props.user_name;
            
            // Format dates
            var startFormatted = moment(info.event.start).format('DD/MM/YYYY HH:mm น.');
            var endFormatted = info.event.end ? moment(info.event.end).format('DD/MM/YYYY HH:mm น.') : '';
            
            document.getElementById('modalStart').innerHTML = '<i class="fa-regular fa-clock me-1 text-muted"></i> ' + startFormatted;
            document.getElementById('modalEnd').innerHTML = '<i class="fa-regular fa-clock me-1 text-muted"></i> ' + endFormatted;

            // URL Link
            if (props.meeting_url) {
                document.getElementById('modalMeetingUrl').innerHTML = '<a href="' + props.meeting_url + '" target="_blank" class="text-primary text-break"><i class="fa-solid fa-link me-1"></i> ' + props.meeting_url + '</a>';
            } else {
                document.getElementById('modalMeetingUrl').innerHTML = '-';
            }

            // QR Code Generation
            var qrContainer = document.getElementById('modalQrContainer');
            if (props.meeting_url) {
                qrContainer.classList.remove('d-none');
                var qr = new QRious({
                    element: document.getElementById('qrCanvas'),
                    value: props.meeting_url,
                    size: 150,
                    level: 'M'
                });
                var btnDownload = document.getElementById('btnDownloadQr');
                btnDownload.href = qr.toDataURL('image/png');
                btnDownload.download = 'QR_' + (props.booking_title || 'Zoom').substring(0, 15).replace(/\s+/g, '_') + '.png';
            } else {
                qrContainer.classList.add('d-none');
            }

            var actionContainer = document.getElementById('modalActionContainer');
            actionContainer.innerHTML = '';
            
            if (props.can_delete) {
                var form = document.createElement('form');
                form.action = 'delete.php';
                form.method = 'POST';
                form.className = 'm-0';
                form.onsubmit = function() {
                    return confirm('คุณแน่ใจหรือไม่ว่าต้องการยกเลิกการจองนี้?');
                };
                
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'booking_id';
                input.value = info.event.id;
                
                var btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'btn btn-outline-danger rounded-pill px-4 shadow-sm';
                btn.innerHTML = '<i class="fa-solid fa-trash-can me-1"></i> ยกเลิกคิว';
                
                form.appendChild(input);
                form.appendChild(btn);
                actionContainer.appendChild(form);
            }

            eventModal.show();
        }
    });
    calendar.render();
});
</script>
