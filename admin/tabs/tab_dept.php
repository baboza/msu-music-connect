<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold text-purple">รายการหน่วยงาน / สาขา</div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small"><tr><th class="ps-4">ID</th><th>ชื่อหน่วยงาน</th><th class="text-center">จัดการ</th></tr></thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                        <tr>
                            <td class="ps-4 text-muted"><?php echo $dept['dept_id']; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($dept['dept_name']); ?></td>
                            <td class="text-center">
                                <form action="settings_action.php" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบ?');">
                                    <input type="hidden" name="action" value="delete_dept">
                                    <input type="hidden" name="dept_id" value="<?php echo $dept['dept_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">ลบ</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 border-top border-purple border-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">เพิ่มหน่วยงานใหม่</h6>
                <form action="settings_action.php" method="POST">
                    <input type="hidden" name="action" value="add_dept">
                    <div class="mb-3"><input type="text" name="dept_name" class="form-control border-purple" required></div>
                    <button type="submit" class="btn btn-purple w-100 rounded-pill fw-bold py-2">บันทึก</button>
                </form>
            </div>
        </div>
    </div>
</div>