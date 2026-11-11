<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$manage_room_roles = ['admin', 'งานอาคารสถานที่'];
if (!hasRole($manage_room_roles)) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';
$room = null;

if (isset($_GET['id'])) {
    $old_room_id = trim($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM practice_rooms WHERE room_id = ?");
    $stmt->execute([$old_room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        header("Location: index.php");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_room_id = trim($_POST['room_id']);
    $room_type = trim($_POST['room_type']);
    $capacity = (int)$_POST['capacity'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $disabled_reason = !$is_active ? trim($_POST['disabled_reason']) : null;

    if (empty($new_room_id) || empty($room_type)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } else {
        try {
            // Check duplicate if ID changed
            if ($new_room_id !== $old_room_id) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM practice_rooms WHERE room_id = ?");
                $check->execute([$new_room_id]);
                if ($check->fetchColumn() > 0) {
                    $error = "รหัสห้องซ้อม '$new_room_id' มีอยู่ในระบบแล้ว";
                }
            }

            if (empty($error)) {
                $stmt = $pdo->prepare("UPDATE practice_rooms SET room_id = ?, room_type = ?, capacity = ?, is_active = ?, disabled_reason = ? WHERE room_id = ?");
                $stmt->execute([$new_room_id, $room_type, $capacity, $is_active, $disabled_reason, $old_room_id]);
                $success = "อัปเดตข้อมูลห้องซ้อมสำเร็จ";
                $old_room_id = $new_room_id; // update local var
                
                // Refresh room data
                $stmt = $pdo->prepare("SELECT * FROM practice_rooms WHERE room_id = ?");
                $stmt->execute([$old_room_id]);
                $room = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $error = "เกิดข้อผิดพลาดฐานข้อมูล: " . $e->getMessage();
        }
    }
}

$page_title = "แก้ไขข้อมูลห้องซ้อมดนตรี";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-dark fw-bold"><i class="fa-solid fa-pen-to-square text-primary me-2"></i>แก้ไขข้อมูลห้องซ้อมดนตรี</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-muted">จัดการห้องซ้อม</a></li>
                    <li class="breadcrumb-item active text-dark fw-bold" aria-current="page">แก้ไขข้อมูล</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm border-0 rounded-4"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success shadow-sm border-0 rounded-4"><i class="fa-solid fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?></div>
        <script>setTimeout(()=>window.location.href='index.php', 1500);</script>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 max-w-lg mx-auto" style="max-width: 600px;">
        <div class="card-body p-4">
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">รหัสห้องซ้อม (เช่น MUA PR99)</label>
                    <input type="text" class="form-control rounded-3" name="room_id" required value="<?php echo htmlspecialchars($room['room_id']); ?>">
                    <small class="text-muted"><i class="fa-solid fa-info-circle"></i> หากแก้ไขรหัสห้อง ประวัติการจองเดิมจะเปลี่ยนรหัสห้องตามอัตโนมัติ (CASCADE UPDATE)</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">ประเภทห้อง (เครื่องดนตรี / รายละเอียด)</label>
                    <input type="text" class="form-control rounded-3" name="room_type" required value="<?php echo htmlspecialchars($room['room_type']); ?>">
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark">ความจุ (คน)</label>
                    <input type="number" class="form-control rounded-3" name="capacity" min="1" required value="<?php echo htmlspecialchars($room['capacity']); ?>">
                </div>
                
                <div class="mb-4">
                    <div class="form-check form-switch form-switch-lg mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActiveCheck" <?php echo (isset($room['is_active']) && $room['is_active']) ? 'checked' : ''; ?> onchange="toggleReason()">
                        <label class="form-check-label fw-bold text-dark ms-2" for="isActiveCheck">เปิดใช้งานห้องนี้</label>
                    </div>
                    
                    <div id="reasonContainer" class="<?php echo (isset($room['is_active']) && $room['is_active']) ? 'd-none' : ''; ?>">
                        <label class="form-label fw-bold text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> เหตุผลที่ปิดใช้งาน (ให้นิสิตทราบ)</label>
                        <input type="text" class="form-control rounded-3 border-danger" name="disabled_reason" id="disabledReasonInput" placeholder="เช่น แอร์เสีย, ซ่อมบำรุง, งดใช้ชั่วคราว" value="<?php echo htmlspecialchars($room['disabled_reason'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="d-flex justify-content-between gap-2 mt-4">
                    <a href="index.php" class="btn btn-light border rounded-pill px-4 fw-bold">กลับ</a>
                    <button type="submit" class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold"><i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
.form-switch-lg .form-check-input {
    height: 1.5rem;
    width: 2.5rem;
    cursor: pointer;
}
.form-switch-lg .form-check-label {
    line-height: 1.5rem;
    cursor: pointer;
}
</style>

<script>
function toggleReason() {
    const isChecked = document.getElementById('isActiveCheck').checked;
    const reasonContainer = document.getElementById('reasonContainer');
    const reasonInput = document.getElementById('disabledReasonInput');
    
    if (!isChecked) {
        reasonContainer.classList.remove('d-none');
        reasonInput.setAttribute('required', 'required');
    } else {
        reasonContainer.classList.add('d-none');
        reasonInput.removeAttribute('required');
    }
}

// Init on load
document.addEventListener('DOMContentLoaded', toggleReason);
</script>

<?php require_once '../../includes/footer.php'; ?>
