<?php
// admin/ajax_students.php
require_once '../auth/session_check.php';
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!hasRole(['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$search = $_GET['search'] ?? '';
$filter_major = $_GET['major'] ?? '';
$filter_year = $_GET['year_level'] ?? '';
$filter_status = $_GET['status'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$limit = 20; // 20 items per page
$offset = ($page - 1) * $limit;

$where_clauses = ["1=1"];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param, $search_param);
}
if ($filter_major !== '') {
    $where_clauses[] = "major = ?";
    $params[] = $filter_major;
}
if ($filter_year !== '') {
    $where_clauses[] = "year_level = ?";
    $params[] = $filter_year;
}
if ($filter_status !== '') {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

// Count Total
$stmt_count = $pdo->prepare("SELECT COUNT(*) FROM students WHERE $where_sql");
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch Data
$stmt_data = $pdo->prepare("SELECT * FROM students WHERE $where_sql ORDER BY student_id DESC LIMIT $limit OFFSET $offset");
$stmt_data->execute($params);
$students = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

ob_start();
if (count($students) == 0) {
    echo '<tr><td colspan="6" class="text-center py-5"><div class="text-muted"><i class="fa-solid fa-user-slash fa-3x mb-3 opacity-50"></i><h6>ไม่พบข้อมูลที่ค้นหา</h6></div></td></tr>';
} else {
    foreach($students as $s) {
        $img = '';
        $prof_pic = $s['profile_picture'] ?? '';
        if (!empty($prof_pic)) {
            $display_pic = str_starts_with($prof_pic, 'http') ? $prof_pic : '../uploads/avatars/' . $prof_pic;
            $img = "<img src='" . htmlspecialchars($display_pic) . "' class='rounded-circle w-100 h-100' style='object-fit:cover;' referrerpolicy='no-referrer'>";
        } else {
            $img = mb_substr($s['first_name'], 0, 1, 'UTF-8'); 
        }
        
        $major = htmlspecialchars($s['major'] ?? '-');
        $year = $s['year_level'] ? 'ปี '.$s['year_level'] : '-';
        $email = htmlspecialchars($s['email'] ?? '-');
        $phone = htmlspecialchars($s['phone_number'] ?? '-');
        
        $badge = '';
        if($s['status'] == 'active') $badge = '<span class="badge bg-success shadow-sm px-2">Active</span>';
        elseif($s['status'] == 'graduated') $badge = '<span class="badge bg-info shadow-sm px-2">Graduated</span>';
        else $badge = '<span class="badge bg-secondary shadow-sm px-2">Inactive</span>';

        ?>
        <tr>
            <td class="ps-4">
                <div class="d-flex align-items-center">
                    <div class="avatar-sm me-3 bg-purple-subtle text-purple fw-bold rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 45px; height: 45px;">
                        <?php echo $img; ?>
                    </div>
                    <div>
                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($s['student_id']); ?></div>
                        <div class="small text-secondary mb-1"><?php echo htmlspecialchars($s['prefix_name'].$s['first_name'].' '.$s['last_name']); ?></div>
                    </div>
                </div>
            </td>
            <td><span class="fw-bold text-secondary"><?php echo $major; ?></span></td>
            <td><div class="small"><?php echo $year; ?></div></td>
            <td>
                <div class="extra-small text-muted"><i class="fa-regular fa-envelope me-1"></i><?php echo $email; ?></div>
                <div class="extra-small text-muted"><i class="fa-solid fa-phone me-1"></i><?php echo $phone; ?></div>
            </td>
            <td class="text-center"><?php echo $badge; ?></td>
            <td class="text-center pe-4 text-nowrap">
                <a href="/e-service/public.php?id=<?php echo htmlspecialchars($s['student_id']); ?>" target="_blank" class="btn btn-sm btn-light border text-info rounded-circle me-1" title="ดูแฟ้มผลงาน (Public Portfolio)">
                    <i class="fa-solid fa-id-badge"></i>
                </a>
                
                <button type="button" class="btn btn-sm btn-light border text-primary rounded-circle me-1 edit-btn" 
                    title="แก้ไขข้อมูล"
                    data-id="<?php echo htmlspecialchars($s['student_id']); ?>"
                    data-prefix="<?php echo htmlspecialchars($s['prefix_name'] ?? ''); ?>"
                    data-fname="<?php echo htmlspecialchars($s['first_name'] ?? ''); ?>"
                    data-lname="<?php echo htmlspecialchars($s['last_name'] ?? ''); ?>"
                    data-major="<?php echo htmlspecialchars($s['major'] ?? ''); ?>"
                    data-year="<?php echo htmlspecialchars($s['year_level'] ?? ''); ?>"
                    data-email="<?php echo htmlspecialchars($s['email'] ?? ''); ?>"
                    data-phone="<?php echo htmlspecialchars($s['phone_number'] ?? ''); ?>"
                    data-status="<?php echo htmlspecialchars($s['status'] ?? ''); ?>">
                    <i class="fa-solid fa-pen"></i>
                </button>
                
                <button type="button" class="btn btn-sm btn-light border text-warning rounded-circle me-1" title="เปลี่ยนรหัสผ่าน"
                    onclick="openPasswordModal('<?php echo htmlspecialchars($s['student_id']); ?>', '<?php echo htmlspecialchars(addslashes($s['first_name'].' '.$s['last_name'])); ?>')">
                    <i class="fa-solid fa-key"></i>
                </button>

                <button type="button" class="btn btn-sm btn-light border text-danger rounded-circle" title="ระงับการใช้งาน"
                    onclick="showConfirmModal({
                        title: 'ยืนยันการระงับการใช้งาน?',
                        message: 'คุณแน่ใจหรือไม่ที่จะระงับการใช้งานนิสิตรหัส <?php echo htmlspecialchars($s['student_id']); ?>?',
                        icon: 'fa-user-slash',
                        btnText: 'ยืนยัน',
                        btnClass: 'btn-danger',
                        callback: function() {
                            document.getElementById('deleteTargetId').value = '<?php echo htmlspecialchars($s['student_id']); ?>';
                            document.getElementById('deleteStudentForm').submit();
                        }
                    })">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php
    }
}
$html_rows = ob_get_clean();

ob_start();
if ($total_pages > 1) {
    echo '<ul class="pagination pagination-sm justify-content-center m-0">';
    $prev_disabled = ($page <= 1) ? 'disabled' : '';
    echo '<li class="page-item '.$prev_disabled.'"><a class="page-link" href="#" onclick="loadPage('.($page-1).'); return false;"><i class="fa-solid fa-chevron-left"></i></a></li>';
    
    for ($i = 1; $i <= $total_pages; $i++) {
        if (abs($page - $i) < 3 || $i == 1 || $i == $total_pages) {
            $active = ($i == $page) ? 'active' : '';
            echo '<li class="page-item '.$active.'"><a class="page-link" href="#" onclick="loadPage('.$i.'); return false;">'.$i.'</a></li>';
        } elseif (abs($page - $i) == 3) {
            echo '<li class="page-item disabled"><span class="page-link border-0 text-muted">...</span></li>';
        }
    }
    
    $next_disabled = ($page >= $total_pages) ? 'disabled' : '';
    echo '<li class="page-item '.$next_disabled.'"><a class="page-link" href="#" onclick="loadPage('.($page+1).'); return false;"><i class="fa-solid fa-chevron-right"></i></a></li>';
    echo '</ul>';
}
$html_pagination = ob_get_clean();

echo json_encode([
    'html' => $html_rows,
    'pagination' => $html_pagination,
    'total' => $total_records
]);
