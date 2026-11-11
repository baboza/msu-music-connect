<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ (ให้เฉพาะ งานพัสดุ และ Admin เข้าถึงได้)
if (!hasRole(['admin', 'งานพัสดุ'])) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: ท่านไม่มีสิทธิ์จัดการข้อมูลวัสดุ";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$error = "";
$success = "";

// 2. จัดการการบันทึกข้อมูล (Add / Edit)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $item_name = trim($_POST['item_name']);
        $category = trim($_POST['category']);
        $stock_qty = (int)$_POST['stock_qty'];
        $received_date = !empty($_POST['received_date']) ? trim($_POST['received_date']) : date('Y-m-d');
        $unit_name = trim($_POST['unit_name']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            if ($_POST['action'] == 'add') {
                $stmt = $pdo->prepare("INSERT INTO supplies_items (item_name, category, stock_qty, received_date, unit_name, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$item_name, $category, $stock_qty, $received_date, $unit_name, $is_active]);
                $_SESSION['msg'] = "เพิ่มรายการวัสดุใหม่เรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            } elseif ($_POST['action'] == 'edit') {
                $item_id = $_POST['item_id'];
                $stmt = $pdo->prepare("UPDATE supplies_items SET item_name=?, category=?, stock_qty=?, received_date=?, unit_name=?, is_active=? WHERE item_id=?");
                $stmt->execute([$item_name, $category, $stock_qty, $received_date, $unit_name, $is_active, $item_id]);
                $_SESSION['msg'] = "อัปเดตข้อมูลวัสดุเรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            }
            header("Location: manage_items.php");
            exit();
        } catch (PDOException $e) {
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// 3. ดึงรายการวัสดุทั้งหมด
$search = $_GET['search'] ?? '';
$sql = "SELECT * FROM supplies_items WHERE item_name LIKE ? ORDER BY category ASC, item_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['%' . $search . '%']);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-boxes-packing me-2"></i>จัดการคลังวัสดุ</h1>
            <p class="text-muted small mb-0">เพิ่ม แก้ไข หรือปรับสต็อกวัสดุอุปกรณ์สำนักงาน</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0 flex-wrap">
            <a href="report.php?tab=stock" class="btn btn-outline-purple rounded-pill px-3 fw-bold shadow-sm hover-lift">
                <i class="fa-solid fa-boxes-stacked me-1"></i> รายงานคงเหลือ
            </a>
            <a href="issue_supplies.php" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm hover-lift">
                <i class="fa-solid fa-hand-holding-box me-1"></i> จ่ายพัสดุ / ตัดสต็อก
            </a>
            <button type="button" class="btn btn-purple rounded-pill px-3 fw-bold shadow-sm hover-lift" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus me-1"></i> เพิ่มวัสดุใหม่
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm border-0 border-start border-<?php echo $_SESSION['msg_type']; ?> border-4 rounded-4">
            <i class="fa-solid <?php echo $_SESSION['msg_type'] == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2"></i> 
            <?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form action="" method="GET" class="row g-2">
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-0" placeholder="ค้นหาชื่อวัสดุ หรือหมวดหมู่..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold">ค้นหา</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4 border-0 py-3">หมวดหมู่</th>
                            <th class="border-0 py-3">รายการวัสดุ</th>
                            <th class="border-0 py-3">วันที่นำเข้า</th>
                            <th class="text-center border-0 py-3">คงเหลือ</th>
                            <th class="border-0 py-3">หน่วยนับ</th>
                            <th class="text-center border-0 py-3">สถานะ</th>
                            <th class="pe-4 text-end border-0 py-3">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted small">ไม่พบข้อมูลรายการวัสดุที่ค้นหา</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                            <tr class="<?php echo $item['is_active'] ? '' : 'table-light opacity-50'; ?>">
                                <td class="ps-4"><span class="badge bg-light text-secondary border px-2 py-1"><?php echo htmlspecialchars($item['category']); ?></span></td>
                                <td class="fw-bold text-dark"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td class="text-muted small">
                                    <i class="fa-regular fa-calendar-check me-1 text-purple"></i>
                                    <?php echo !empty($item['received_date']) ? date('d/m/Y', strtotime($item['received_date'])) : '-'; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $item['stock_qty'] <= 5 ? 'bg-danger' : 'bg-success-subtle text-success'; ?> rounded-pill px-3 py-2 fs-6">
                                        <?php echo $item['stock_qty']; ?>
                                    </span>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars($item['unit_name']); ?></td>
                                <td class="text-center">
                                    <?php echo $item['is_active'] ? 
                                        '<span class="badge bg-success-subtle text-success border border-success"><i class="fa-solid fa-check-circle me-1"></i>พร้อมเบิก</span>' : 
                                        '<span class="badge bg-secondary-subtle text-secondary border border-secondary"><i class="fa-solid fa-ban me-1"></i>ปิดใช้งาน</span>'; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <button class="btn btn-sm btn-outline-purple rounded-pill px-3 fw-bold" onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไข/ปรับสต็อก
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-purple text-white border-0 py-3 rounded-top-4">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>เพิ่มวัสดุใหม่เข้าคลัง</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">ชื่อวัสดุอุปกรณ์ <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" class="form-control border-secondary shadow-sm" required placeholder="เช่น กระดาษ A4 80 แกรม">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-dark small">หมวดหมู่</label>
                                <input type="text" name="category" class="form-control border-secondary shadow-sm" placeholder="เช่น สายสัญญาณ" list="categoryOptions">
                                <datalist id="categoryOptions">
                                    <option value="อุปกรณ์คอมพิวเตอร์">
                                    <option value="สายสัญญาณ">
                                    <option value="เครื่องเขียน">
                                    <option value="อุปกรณ์ทำความสะอาด">
                                </datalist>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-dark small">หน่วยนับ <span class="text-danger">*</span></label>
                                <input type="text" name="unit_name" class="form-control border-secondary shadow-sm" required placeholder="เช่น รีม, เส้น, อัน">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold text-dark small">จำนวนสต็อกเริ่มต้น</label>
                                <input type="number" name="stock_qty" class="form-control border-secondary shadow-sm" value="0" min="0">
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold text-dark small">วันที่นำเข้า / รับเข้าคลัง</label>
                                <input type="date" name="received_date" class="form-control border-secondary shadow-sm" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="form-check form-switch bg-light p-3 rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="is_active" id="addActive" checked>
                            <label class="form-check-label fw-bold text-success small pt-1" for="addActive">เปิดให้บุคลากรขอเบิกได้ทันที</label>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 rounded-bottom-4">
                        <button type="button" class="btn btn-white border rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-purple rounded-pill px-4 fw-bold shadow-sm">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-dark text-white border-0 py-3 rounded-top-4">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i>แก้ไขข้อมูล / ปรับยอดสต็อก</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="item_id" id="edit_id">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">ชื่อวัสดุอุปกรณ์ <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" id="edit_name" class="form-control border-secondary shadow-sm" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-dark small">หมวดหมู่</label>
                                <input type="text" name="category" id="edit_category" class="form-control border-secondary shadow-sm">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold text-dark small">หน่วยนับ <span class="text-danger">*</span></label>
                                <input type="text" name="unit_name" id="edit_unit" class="form-control border-secondary shadow-sm" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold text-primary small">ยอดคงคลังปัจจุบัน</label>
                                <input type="number" name="stock_qty" id="edit_stock" class="form-control form-control-lg fw-bold border-primary shadow-sm text-primary" required>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold text-dark small">วันที่นำเข้า / รับเข้าคลัง</label>
                                <input type="date" name="received_date" id="edit_received_date" class="form-control border-secondary shadow-sm">
                            </div>
                        </div>
                        <div class="form-check form-switch bg-light p-3 rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="is_active" id="editActive">
                            <label class="form-check-label fw-bold text-dark small pt-1" for="editActive">เปิดให้เบิกใช้งานในระบบ</label>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 rounded-bottom-4">
                        <button type="button" class="btn btn-white border rounded-pill px-4 fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm">บันทึกการเปลี่ยนแปลง</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
function editItem(item) {
    document.getElementById('edit_id').value = item.item_id;
    document.getElementById('edit_name').value = item.item_name;
    document.getElementById('edit_category').value = item.category;
    document.getElementById('edit_unit').value = item.unit_name;
    document.getElementById('edit_stock').value = item.stock_qty;
    document.getElementById('edit_received_date').value = item.received_date || '';
    document.getElementById('editActive').checked = (item.is_active == 1);
    
    var editModal = new bootstrap.Modal(document.getElementById('editItemModal'));
    editModal.show();
}
</script>

<style>
    :root { --msu-purple: #39048f; }
    .bg-purple { background-color: var(--msu-purple); }
    .text-purple { color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; transition: all 0.2s; }
    .btn-purple:hover { background-color: #2a004d; color: white; }
    .btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
    .btn-outline-purple:hover { background-color: var(--msu-purple); color: white; }
    
    .hover-lift { transition: transform 0.2s, box-shadow 0.2s; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important; }
    .form-control:focus, .form-select:focus { border-color: var(--msu-purple); box-shadow: 0 0 0 0.25rem rgba(57, 4, 143,0.15); }
</style>

<?php require_once '../../includes/footer.php'; ?>