</div> <!-- End Container -->

<!-- Dynamic Enterprise Docked Bottom Navigation -->
<div class="mobile-bottom-nav">
    <a href="index.php" class="nav-item-custom <?= ($current_page == 'index.php') ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i>
        <span>หน้าหลัก</span>
    </a>
    
    <a href="worklog.php" class="nav-item-custom <?= ($current_page == 'worklog.php' || $current_page == 'worklog_form.php') ? 'active' : '' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>บันทึกงาน</span>
    </a>
    
    <?php if(hasRole(['admin', 'executive', 'head', 'คณบดี', 'รองคณบดีฝ่ายบริหาร', 'รองคณบดีฝ่ายวิชาการ', 'หัวหน้าสำนักงาน'])): ?>
    <a href="approvals.php" class="nav-item-custom <?= ($current_page == 'approvals.php') ? 'active' : '' ?>">
        <i class="fa-solid fa-clipboard-check"></i>
        <span>อนุมัติ</span>
        <?php if (!empty($mobile_pending_badge) && $mobile_pending_badge > 0): ?>
            <span class="nav-badge-dot"><?= $mobile_pending_badge > 99 ? '99+' : $mobile_pending_badge ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    
    <a href="settings.php" class="nav-item-custom <?= ($current_page == 'settings.php' || $current_page == 'profile.php') ? 'active' : '' ?>">
        <i class="fa-solid fa-sliders"></i>
        <span>ตั้งค่า</span>
    </a>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Smooth transitions indicator
    document.body.addEventListener('htmx:configRequest', function(evt) {
        // Optional request indicator
    });
</script>

</body>
</html>