<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold text-purple">รายการประเภทการลา</div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small"><tr><th class="ps-4">ID</th><th>ชื่อประเภทการลา</th><th class="text-center">จัดการ</th></tr></thead>
                    <tbody>
                        <?php foreach ($leave_types as $lt): ?>
                        <tr>
                            <td class="ps-4 text-muted"><?php echo $lt['type_id']; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($lt['type_name']); ?></td>
                            <td class="text-center">
                                <form action="settings_action.php" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบ?');">
                                    <input type="hidden" name="action" value="delete_leave_type">
                                    <input type="hidden" name="type_id" value="<?php echo $lt['type_id']; ?>">
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
                <h6 class="fw-bold mb-3">เพิ่มประเภทการลาใหม่</h6>
                <form action="settings_action.php" method="POST">
                    <input type="hidden" name="action" value="add_leave_type">
                    <div class="mb-3"><input type="text" name="type_name" class="form-control border-purple" required></div>
                    <button type="submit" class="btn btn-purple w-100 rounded-pill fw-bold py-2">บันทึก</button>
                </form>
            </div>
        </div>
    </div>
</div>