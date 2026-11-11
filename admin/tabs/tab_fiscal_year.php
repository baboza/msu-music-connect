<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 fw-bold text-purple d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-calendar-days me-2"></i>รายการปีงบประมาณ</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th class="ps-4" width="20%">ปีงบประมาณ</th>
                            <th>สถานะ</th>
                            <th class="text-center" width="30%">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fiscal_years)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">ยังไม่ได้กำหนดปีงบประมาณ</td></tr>
                        <?php else: ?>
                            <?php foreach ($fiscal_years as $fy): ?>
                            <tr>
                                <td class="ps-4 fw-bold fs-5 text-dark"><?php echo htmlspecialchars($fy['year_name']); ?></td>
                                <td>
                                    <?php if ($fy['is_active'] == 1): ?>
                                        <span class="badge bg-success shadow-sm px-3 py-2 rounded-pill"><i class="fa-solid fa-check-circle me-1"></i> ปีปัจจุบัน</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary opacity-50 px-3 py-2 rounded-pill">ปีอดีต/อนาคต</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($fy['is_active'] != 1): ?>
                                        <form action="settings_action.php" method="POST" class="d-inline" onsubmit="return confirm('ตั้งค่า <?php echo $fy['year_name']; ?> เป็นปีงบประมาณปัจจุบัน?');">
                                            <input type="hidden" name="action" value="set_active_fiscal_year">
                                            <input type="hidden" name="year_name" value="<?php echo $fy['year_name']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3 me-1">ตั้งเป็นปีปัจจุบัน</button>
                                        </form>
                                        <form action="settings_action.php" method="POST" class="d-inline" onsubmit="return confirm('ยืนยันการลบปีงบประมาณนี้? ข้อมูลโครงการที่ผูกไว้อาจได้รับผลกระทบ');">
                                            <input type="hidden" name="action" value="delete_fiscal_year">
                                            <input type="hidden" name="year_name" value="<?php echo $fy['year_name']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">ลบ</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted extra-small"><i class="fa-solid fa-lock me-1"></i>ไม่สามารถลบปีปัจจุบันได้</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 border-top border-purple border-4 mt-4 mt-lg-0">
            <div class="card-body p-4 bg-light">
                <h6 class="fw-bold mb-3"><i class="fa-solid fa-plus-circle me-2 text-purple"></i>เพิ่มปีงบประมาณใหม่</h6>
                <form action="settings_action.php" method="POST">
                    <input type="hidden" name="action" value="add_fiscal_year">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ปี พ.ศ.</label>
                        <input type="number" name="year_name" class="form-control border-purple shadow-sm fw-bold text-center fs-5" placeholder="เช่น <?php echo date('Y')+543; ?>" min="2560" max="2600" required>
                        <div class="form-text small mt-2">พิมพ์เฉพาะตัวเลข 4 หลัก</div>
                    </div>
                    <button type="submit" class="btn btn-purple w-100 shadow rounded-pill fw-bold py-2"><i class="fa-solid fa-save me-2"></i> บันทึก</button>
                </form>
            </div>
        </div>
    </div>
</div>