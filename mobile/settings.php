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
            <h5 class="fw-bold mb-0 text-dark">ตั้งค่าการใช้งาน</h5>
            <small class="text-muted">จัดการบัญชีและการตั้งค่าระบบ</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-gear"></i>
        </div>
    </div>

    <!-- Profile Box -->
    <div class="bento-box p-4 mb-3 text-center">
        <div class="position-relative d-inline-block mb-3">
            <?php if (!empty($current_avatar)): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($current_avatar) ?>" alt="Profile" class="rounded-circle shadow-sm" width="86" height="86" style="border: 3px solid var(--primary-light); object-fit: cover;">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($full_name) ?>&background=35005e&color=fff&size=86" alt="Profile" class="rounded-circle shadow-sm" style="border: 3px solid var(--primary-light);">
            <?php endif; ?>
            <a href="profile.php" class="position-absolute bottom-0 end-0 bg-purple text-white rounded-circle d-flex align-items-center justify-content-center border border-white border-2 shadow-sm" style="width: 28px; height: 28px; text-decoration: none;">
                <i class="fa-solid fa-pen" style="font-size: 11px;"></i>
            </a>
        </div>
        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($full_name) ?></h5>
        <p class="small text-muted mb-3"><?= htmlspecialchars($_SESSION['roles'][0] ?? 'บุคลากร') ?></p>
        <a href="profile.php" class="btn btn-outline-primary rounded-pill px-4 btn-sm fw-bold">จัดการข้อมูลส่วนตัว</a>
    </div>

    <h6 class="fw-bold mb-2 text-muted px-2 small text-uppercase">ลักษณะที่ปรากฏ (Appearance)</h6>
    <div class="bento-box p-0 mb-3 overflow-hidden">
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center p-3 border-0">
                <div class="d-flex align-items-center">
                    <div class="app-icon-container me-3" style="width: 38px; height: 38px; font-size: 1rem;">
                        <i class="fa-solid fa-moon"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">Dark Mode</h6>
                        <small class="text-muted">สลับโหมดมืด / สว่าง</small>
                    </div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="darkModeSwitch">
                </div>
            </li>
        </ul>
    </div>

    <h6 class="fw-bold mb-2 text-muted px-2 small text-uppercase">ข้อมูลระบบ (System)</h6>
    <div class="bento-box p-0 mb-4 overflow-hidden">
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between align-items-center p-3 border-0">
                <div class="d-flex align-items-center">
                    <div class="app-icon-container me-3" style="width: 38px; height: 38px; font-size: 1rem;">
                        <i class="fa-solid fa-circle-info"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">เวอร์ชันระบบ</h6>
                        <small class="text-muted">Music MSU Connect Mobile</small>
                    </div>
                </div>
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill">v2.1.0 (ERP 2.0)</span>
            </li>

        </ul>
    </div>

    <div class="mb-4 px-1">
        <a href="../auth/logout.php?from=mobile" class="btn btn-outline-danger w-100 rounded-pill py-2 fw-bold d-flex justify-content-center align-items-center" hx-disable>
            <i class="fa-solid fa-power-off me-2"></i> ออกจากระบบ (Logout)
        </a>
    </div>

    <div style="height: 60px;"></div>
</div>

<script>
    function initDarkModeToggle() {
        const darkModeSwitch = document.getElementById("darkModeSwitch");
        if (!darkModeSwitch) return;

        // เช็คค่าจาก LocalStorage
        if (localStorage.getItem("theme") === "dark") {
            document.documentElement.classList.add("dark-mode");
            darkModeSwitch.checked = true;
        } else {
            darkModeSwitch.checked = false;
        }

        // Remove old listener to prevent duplicates if htmx swaps multiple times
        darkModeSwitch.replaceWith(darkModeSwitch.cloneNode(true));
        const newSwitch = document.getElementById("darkModeSwitch");

        newSwitch.addEventListener("change", function() {
            if (this.checked) {
                document.documentElement.classList.add("dark-mode");
                localStorage.setItem("theme", "dark");
            } else {
                document.documentElement.classList.remove("dark-mode");
                localStorage.setItem("theme", "light");
            }
        });
    }

    // Call immediately for direct load or HTMX swap
    initDarkModeToggle();
</script>

<?php require_once 'includes/footer.php'; ?>
