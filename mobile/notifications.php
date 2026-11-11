<?php
require_once 'auth_check.php';
require_once 'includes/header.php';
?>

<div class="mt-2">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ส่งแจ้งเตือนนิสิต</h5>
            <small class="text-muted">Broadcast Push Notifications</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-paper-plane"></i>
        </div>
    </div>

    <div class="bento-box p-4 mb-4">
        <form id="mobileNotiForm">
            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">หัวข้อแจ้งเตือน <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="notiTitle" name="title" required placeholder="เช่น ประกาศด่วน เรื่องการเรียนการสอน...">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">ข้อความรายละเอียด <span class="text-danger">*</span></label>
                <textarea class="form-control" id="notiBody" name="body" rows="3" required placeholder="ระบุข้อความแจ้งเตือนที่ต้องการส่ง..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold small text-muted">แนบลิงก์ URL <small class="text-muted fw-normal">(ถ้ามี)</small></label>
                <input type="url" class="form-control" id="notiLink" name="link" placeholder="https://...">
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold small text-muted">รหัสนิสิตเป้าหมาย <small class="text-muted fw-normal">(เว้นว่างเพื่อส่งทุกคน)</small></label>
                <input type="text" class="form-control" id="targetStudent" name="target_student" placeholder="เช่น 6401050001 (หรือเว้นว่าง)">
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm" id="btnSend">
                <i class="fa-solid fa-paper-plane me-2"></i> ส่งแจ้งเตือนทันที
            </button>
        </form>

        <div id="resultBox" class="mt-4" style="display: none;"></div>
    </div>

    <div style="height: 60px;"></div>
</div>

<script>
document.getElementById('mobileNotiForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const title = document.getElementById('notiTitle').value.trim();
    const link = document.getElementById('notiLink').value.trim();
    const target = document.getElementById('targetStudent').value.trim();
    const body = document.getElementById('notiBody').value.trim();
    const btnSend = document.getElementById('btnSend');
    const resultBox = document.getElementById('resultBox');
    
    if(!title || !body) return;

    if(!target) {
        if(!confirm('จะส่งแจ้งเตือนให้ "ทุกคน" แน่ใจหรือไม่?')) return;
    }

    btnSend.disabled = true;
    btnSend.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> กำลังส่ง...';
    resultBox.style.display = 'none';

    const formData = new FormData();
    formData.append('title', title);
    formData.append('body', body);
    if(link) formData.append('link', link);
    if(target) formData.append('target_student', target);

    fetch('../modules/notifications/send_notification.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        resultBox.style.display = 'block';
        if(data.status === 'success') {
            resultBox.innerHTML = `<div class="alert alert-success rounded-4 border-0 shadow-sm"><i class="fa-solid fa-circle-check me-2"></i>ส่งสำเร็จ! (DB: ${data.db_success}, Push: ${data.push_success})</div>`;
            document.getElementById('mobileNotiForm').reset();
        } else {
            resultBox.innerHTML = `<div class="alert alert-danger rounded-4 border-0 shadow-sm"><i class="fa-solid fa-circle-xmark me-2"></i>${data.message}</div>`;
        }
    })
    .catch(error => {
        resultBox.style.display = 'block';
        resultBox.innerHTML = `<div class="alert alert-danger rounded-4 border-0 shadow-sm">เกิดข้อผิดพลาดในการเชื่อมต่อ</div>`;
    })
    .finally(() => {
        btnSend.disabled = false;
        btnSend.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i> ส่งแจ้งเตือนทันที';
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
