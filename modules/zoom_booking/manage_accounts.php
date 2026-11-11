<?php
session_start();
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// Only admins can access
if (!isset($_SESSION['roles']) || !in_array('admin', $_SESSION['roles'])) {
    $_SESSION['msg'] = "ไม่มีสิทธิ์เข้าถึงหน้านี้";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

// Handle Form Submissions (Create / Update / Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $account_name = trim($_POST['account_name']);
        $email = trim($_POST['email']);
        $meeting_id = trim($_POST['meeting_id']);
        $passcode = trim($_POST['passcode']);
        $meeting_url = trim($_POST['meeting_url']);
        
        $stmt = $pdo->prepare("INSERT INTO zoom_accounts (account_name, email, meeting_id, passcode, meeting_url) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$account_name, $email, $meeting_id, $passcode, $meeting_url])) {
            $_SESSION['msg'] = "เพิ่มบัญชี Zoom เรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }
    } 
    elseif ($action === 'edit') {
        $id = $_POST['account_id'];
        $account_name = trim($_POST['account_name']);
        $email = trim($_POST['email']);
        $meeting_id = trim($_POST['meeting_id']);
        $passcode = trim($_POST['passcode']);
        $meeting_url = trim($_POST['meeting_url']);
        
        $stmt = $pdo->prepare("UPDATE zoom_accounts SET account_name = ?, email = ?, meeting_id = ?, passcode = ?, meeting_url = ? WHERE id = ?");
        if ($stmt->execute([$account_name, $email, $meeting_id, $passcode, $meeting_url, $id])) {
            $_SESSION['msg'] = "อัปเดตข้อมูลบัญชี Zoom เรียบร้อยแล้ว";
            $_SESSION['msg_type'] = "success";
        }
    } 
    elseif ($action === 'delete') {
        $id = $_POST['account_id'];
        
        // Check if there are active bookings
        $check = $pdo->prepare("SELECT COUNT(*) FROM zoom_bookings WHERE zoom_account_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            $_SESSION['msg'] = "ไม่สามารถลบได้ เนื่องจากมีประวัติการจองผูกอยู่กับบัญชีนี้";
            $_SESSION['msg_type'] = "danger";
        } else {
            $stmt = $pdo->prepare("DELETE FROM zoom_accounts WHERE id = ?");
            if ($stmt->execute([$id])) {
                $_SESSION['msg'] = "ลบบัญชี Zoom เรียบร้อยแล้ว";
                $_SESSION['msg_type'] = "success";
            }
        }
    }
    
    header("Location: manage_accounts.php");
    exit();
}

// Fetch all accounts
$stmt = $pdo->query("SELECT * FROM zoom_accounts ORDER BY id ASC");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="pt-4 pb-2 mb-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1">
                <i class="fa-solid fa-gear me-2 text-primary"></i> 
                จัดการบัญชี Zoom
            </h1>
            <p class="text-muted mb-0">เพิ่ม แก้ไข หรือลบบัญชี Zoom ที่เปิดให้จองในระบบ</p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fa-solid fa-plus me-1"></i> เพิ่มบัญชีใหม่
            </button>
        </div>
    </div>

    <?php if(isset($_SESSION['msg'])): 
        $alert_type = isset($_SESSION['msg_type']) && $_SESSION['msg_type'] == 'danger' ? 'danger' : 'success';
    ?>
        <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
            <i class="fa-solid <?= $alert_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <strong><?php echo $_SESSION['msg']; unset($_SESSION['msg']); unset($_SESSION['msg_type']); ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 bg-white">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-secondary small text-uppercase">
                        <tr>
                            <th>ชื่อบัญชี</th>
                            <th>อีเมล</th>
                            <th>Meeting ID</th>
                            <th>รหัสผ่าน (Passcode)</th>
                            <th>ลิงก์ (Meeting URL)</th>
                            <th class="text-end">เครื่องมือ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">ยังไม่มีข้อมูลบัญชี Zoom</td></tr>
                        <?php else: ?>
                            <?php foreach($accounts as $acc): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($acc['account_name']) ?></td>
                                <td><?= htmlspecialchars($acc['email']) ?></td>
                                <td><?= htmlspecialchars($acc['meeting_id']) ?: '<span class="text-muted">-</span>' ?></td>
                                <td><?= htmlspecialchars($acc['passcode']) ?: '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <?php if(!empty($acc['meeting_url'])): ?>
                                        <a href="<?= htmlspecialchars($acc['meeting_url']) ?>" target="_blank" class="btn btn-sm btn-light rounded-pill px-3">
                                            <i class="fa-solid fa-link text-info"></i> เปิดลิงก์
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-warning rounded-circle icon-btn me-1" 
                                            data-bs-toggle="modal" data-bs-target="#editModal<?= $acc['id'] ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form action="" method="POST" class="d-inline m-0" onsubmit="return confirm('คุณแน่ใจว่าต้องการลบบัญชีนี้?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle icon-btn">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Modals -->
<?php if (!empty($accounts)): ?>
    <?php foreach($accounts as $acc): ?>
        <!-- Edit Modal -->
        <div class="modal fade" id="editModal<?= $acc['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                        <div class="modal-header border-0 pb-0 pt-4 px-4">
                            <h5 class="fw-bold"><i class="fa-solid fa-pen text-warning me-2"></i>แก้ไขบัญชี Zoom</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">ชื่อบัญชี</label>
                                <input type="text" name="account_name" class="form-control rounded-3" value="<?= htmlspecialchars($acc['account_name']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">อีเมล (Email)</label>
                                <input type="email" name="email" class="form-control rounded-3" value="<?= htmlspecialchars($acc['email']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">Meeting ID</label>
                                <input type="text" name="meeting_id" class="form-control rounded-3" value="<?= htmlspecialchars($acc['meeting_id']) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">รหัสผ่าน (Passcode)</label>
                                <input type="text" name="passcode" class="form-control rounded-3" value="<?= htmlspecialchars($acc['passcode']) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">ลิงก์ห้อง (Meeting URL)</label>
                                <input type="url" name="meeting_url" class="form-control rounded-3" value="<?= htmlspecialchars($acc['meeting_url']) ?>">
                            </div>
                        </div>
                        <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-between">
                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                            <button type="submit" class="btn btn-warning rounded-pill px-4 shadow-sm text-white fw-bold">บันทึกการแก้ไข</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="" method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <h5 class="fw-bold"><i class="fa-solid fa-plus text-primary me-2"></i>เพิ่มบัญชี Zoom ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">ชื่อบัญชี <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control rounded-3" placeholder="เช่น Zoom 3 (ห้องประชุมเล็ก)" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">อีเมล (Email) <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control rounded-3" placeholder="zoom3@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Meeting ID</label>
                        <input type="text" name="meeting_id" class="form-control rounded-3" placeholder="123 456 7890">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">รหัสผ่าน (Passcode)</label>
                        <input type="text" name="passcode" class="form-control rounded-3" placeholder="ปล่อยว่างได้หากไม่มี">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">ลิงก์ห้อง (Meeting URL)</label>
                        <input type="url" name="meeting_url" class="form-control rounded-3" placeholder="https://zoom.us/j/...">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .icon-btn { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
</style>

<?php require_once '../../includes/footer.php'; ?>
