<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold text-purple d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-list me-2"></i>รายการหัวข้องานทั้งหมด</span>
                <span class="badge bg-purple-subtle text-purple"><?php echo count($tasks_list); ?> รายการ</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-muted text-uppercase sticky-top">
                            <tr>
                                <th class="ps-4" width="30%">สิทธิ์การใช้งาน (Role)</th>
                                <th>หัวข้องานที่จะปรากฏ</th>
                                <th class="text-center" width="20%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks_list as $t): ?>
                            <tr>
                                <td class="ps-4"><span class="badge bg-gold-subtle text-gold border border-warning px-2 py-1"><?php echo htmlspecialchars($t['role_name']); ?></span></td>
                                <td class="fw-bold text-dark"><?php echo htmlspecialchars($t['task_name']); ?></td>
                                <td class="text-center text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 me-1" 
                                            onclick="openEditTaskModal(
                                                '<?php echo $t['task_id']; ?>', 
                                                '<?php echo $t['role_id']; ?>', 
                                                '<?php echo htmlspecialchars(addslashes($t['task_name'])); ?>'
                                            )">
                                        แก้ไข
                                    </button>
                                    
                                    <form action="settings_action.php" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบ?');">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
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
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 border-top border-purple border-4">
            <div class="card-body p-4 bg-light">
                <h6 class="fw-bold mb-3">กำหนดหัวข้องานใหม่</h6>
                <form action="settings_action.php" method="POST">
                    <input type="hidden" name="action" value="add_task">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">เลือกสิทธิ์ (Role)</label>
                        <select name="role_id" class="form-select border-purple" required>
                            <?php foreach ($roles_list as $r): ?>
                                <option value="<?php echo $r['role_id']; ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">ชื่องานที่ปฏิบัติ</label>
                        <textarea name="task_name" class="form-control border-purple" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-purple w-100 shadow rounded-pill fw-bold">บันทึกหัวข้องาน</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header bg-warning text-dark border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>แก้ไขหัวข้องาน</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form action="settings_action.php" method="POST">
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" name="action" value="edit_task">
                    <input type="hidden" name="task_id" id="edit_task_id">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">สิทธิ์ที่มองเห็น (Role)</label>
                        <select name="role_id" id="edit_role_id" class="form-select border-warning" required>
                            <?php foreach ($roles_list as $r): ?>
                                <option value="<?php echo $r['role_id']; ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">ชื่องานที่ปฏิบัติ</label>
                        <textarea name="task_name" id="edit_task_name" class="form-control border-warning fw-bold" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-white">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditTaskModal(taskId, roleId, taskName) {
    document.getElementById('edit_task_id').value = taskId;
    document.getElementById('edit_role_id').value = roleId;
    document.getElementById('edit_task_name').value = taskName;
    
    var editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
    editModal.show();
}
</script>