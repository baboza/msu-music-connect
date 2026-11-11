/**
 * Music E-office Main JavaScript
 */

document.addEventListener("DOMContentLoaded", function() {
    
    // 1. Active Link Auto-highlight
    // ตรวจสอบ URL ปัจจุบันและไปเลือกเมนูใน Sidebar ให้เป็นสีม่วงอัตโนมัติ
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.sidebar .nav-link');
    
    navLinks.forEach(link => {
        if (link.getAttribute('href').includes(currentPath) && currentPath !== '/music-e-office/') {
            link.classList.add('active');
        }
    });

    // 2. Tooltip Activation (ของ Bootstrap 5)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });

    // 3. ปรับขนาด Sidebar อัตโนมัติบนมือถือ
    const toggleBtn = document.querySelector('.navbar-toggler');
    if(toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
    }

    // 4. แสดงข้อความทักทายตามเวลา (Premium Touch)
    console.log("Music E-office System Ready...");
});

// ฟังก์ชันสำหรับแจ้งเตือนยืนยันการลบหรือยกเลิก
function confirmAction(message = "ยืนยันการทำรายการ?") {
    return confirm(message);
}