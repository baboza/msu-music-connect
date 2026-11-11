<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 border-top border-purple border-4">
            <div class="card-header bg-white py-3 fw-bold text-purple"><i class="fa-solid fa-clock me-2"></i>เวลาเข้าใช้งานระบบบันทึกงาน</div>
            <div class="card-body p-4 bg-light">
                <form action="settings_action.php" method="POST">
                    <input type="hidden" name="action" value="update_worklog_settings">
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เวลาเปิดระบบ</label>
                            <input type="time" name="open_time" class="form-control border-success text-center fw-bold fs-5" value="<?php echo htmlspecialchars($open_time); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เวลาตัดรอบ (ส่งล่าช้า)</label>
                            <input type="time" name="late_time" class="form-control border-danger text-center fw-bold fs-5" value="<?php echo htmlspecialchars($late_time); ?>" required>
                        </div>
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-purple px-5 py-2 shadow-sm fw-bold rounded-pill">บันทึกการตั้งค่า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>