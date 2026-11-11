<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$post_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($post_id === 0) {
    echo "<script>alert('ไม่พบรหัสบทความ'); window.location.href='index.php';</script>";
    exit();
}

// =========================================================
// 1. ระบบลบโพสต์ (Delete Post) และลบไฟล์แนบในเครื่อง
// =========================================================
if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $stmt_check = $pdo->prepare("SELECT user_id FROM km_posts WHERE post_id = ?");
    $stmt_check->execute([$post_id]);
    $owner_id = $stmt_check->fetchColumn();

    if ($owner_id == $user_id || hasRole(['admin'])) {
        // ลบไฟล์แนบออกจาก Physical Server ก่อน
        $stmt_files = $pdo->prepare("SELECT file_path FROM km_attachments WHERE post_id = ?");
        $stmt_files->execute([$post_id]);
        while ($file = $stmt_files->fetch(PDO::FETCH_ASSOC)) {
            $file_path = '../../uploads/km_attachments/' . $file['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $pdo->prepare("DELETE FROM km_posts WHERE post_id = ?")->execute([$post_id]);
        echo "<script>alert('ลบองค์ความรู้เรียบร้อยแล้ว'); window.location.href='index.php';</script>";
        exit();
    } else {
        echo "<script>alert('ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์ลบโพสต์นี้'); window.location.href='view.php?id=$post_id';</script>";
        exit();
    }
}

// =========================================================
// 2. ระบบลบคอมเมนต์ (Delete Comment)
// =========================================================
if (isset($_GET['action']) && $_GET['action'] == 'delete_comment' && isset($_GET['comment_id'])) {
    $comment_id = intval($_GET['comment_id']);
    
    $stmt_chk_cmt = $pdo->prepare("SELECT user_id FROM km_comments WHERE comment_id = ?");
    $stmt_chk_cmt->execute([$comment_id]);
    $cmt_owner = $stmt_chk_cmt->fetchColumn();

    if ($cmt_owner == $user_id || hasRole(['admin'])) {
        $pdo->prepare("DELETE FROM km_comments WHERE comment_id = ?")->execute([$comment_id]);
        header("Location: view.php?id=" . $post_id . "#comments_section");
        exit();
    }
}

// =========================================================
// 3. ระบบกดถูกใจ (Toggle Like)
// =========================================================
$stmt_check_like = $pdo->prepare("SELECT COUNT(*) FROM km_likes WHERE post_id = ? AND user_id = ?");
$stmt_check_like->execute([$post_id, $user_id]);
$has_liked = $stmt_check_like->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_like'])) {
    try {
        $pdo->beginTransaction();
        if ($has_liked) {
            $pdo->prepare("DELETE FROM km_likes WHERE post_id = ? AND user_id = ?")->execute([$post_id, $user_id]);
            $pdo->prepare("UPDATE km_posts SET like_count = like_count - 1 WHERE post_id = ?")->execute([$post_id]);
        } else {
            $pdo->prepare("INSERT INTO km_likes (post_id, user_id) VALUES (?, ?)")->execute([$post_id, $user_id]);
            $pdo->prepare("UPDATE km_posts SET like_count = like_count + 1 WHERE post_id = ?")->execute([$post_id]);
        }
        $pdo->commit();
        header("Location: view.php?id=" . $post_id);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
    }
}

// =========================================================
// 4. ระบบเพิ่มคอมเมนต์ (Add Comment)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment'])) {
    $comment_text = trim($_POST['comment_text']);
    if (!empty($comment_text)) {
        $stmt_comment = $pdo->prepare("INSERT INTO km_comments (post_id, user_id, comment_text) VALUES (?, ?, ?)");
        $stmt_comment->execute([$post_id, $user_id, $comment_text]);
        header("Location: view.php?id=" . $post_id . "#comments_section");
        exit();
    }
}

// =========================================================
// 5. ระบบนับยอดวิว (View Count)
// =========================================================
if (!isset($_SESSION['viewed_km_posts'][$post_id])) {
    $pdo->prepare("UPDATE km_posts SET view_count = view_count + 1 WHERE post_id = ?")->execute([$post_id]);
    $_SESSION['viewed_km_posts'][$post_id] = true; 
}

// =========================================================
// 6. ดึงข้อมูลเนื้อหา (Post + Comments + Attachments)
// =========================================================
$sql = "SELECT k.*, u.first_name, u.last_name, u.profile_picture, d.dept_name 
        FROM km_posts k
        JOIN users u ON k.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE k.post_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    echo "<script>alert('ไม่พบบทความ'); window.location.href='index.php';</script>";
    exit();
}

$comments = $pdo->prepare("SELECT c.*, u.first_name, u.last_name, u.profile_picture FROM km_comments c JOIN users u ON c.user_id = u.user_id WHERE c.post_id = ? ORDER BY c.created_at ASC");
$comments->execute([$post_id]);
$all_comments = $comments->fetchAll(PDO::FETCH_ASSOC);

$stmt_files = $pdo->prepare("SELECT * FROM km_attachments WHERE post_id = ? ORDER BY uploaded_at ASC");
$stmt_files->execute([$post_id]);
$attachments = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันช่วย
function getPostTypeBadge($type) {
    if ($type == 'best_practice') return '<span class="badge bg-warning text-dark border border-warning shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-star me-1 text-danger"></i> Best Practice</span>';
    if ($type == 'lesson_learned') return '<span class="badge bg-info text-white shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-lightbulb me-1"></i> Lesson Learned</span>';
    return '<span class="badge bg-success text-white shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-share-nodes me-1"></i> Sharing</span>';
}

function formatSize($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1024, 2) . ' KB';
}

$can_edit = ($post['user_id'] == $user_id || hasRole(['admin']));
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-center pt-4 pb-3 mb-3 d-print-none">
        <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold shadow-sm hover-lift">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-light border text-dark rounded-pill px-3 shadow-sm hover-lift">
                <i class="fa-solid fa-print me-1"></i> พิมพ์
            </button>
            <?php if($can_edit): ?>
                <a href="edit.php?id=<?php echo $post_id; ?>" class="btn btn-outline-primary rounded-pill px-3 shadow-sm hover-lift">
                    <i class="fa-solid fa-pen me-1"></i> แก้ไข
                </a>
                <a href="view.php?id=<?php echo $post_id; ?>&action=delete" class="btn btn-outline-danger rounded-pill px-3 shadow-sm hover-lift" onclick="return confirm('ยืนยันการลบองค์ความรู้และไฟล์แนบทั้งหมด?');">
                    <i class="fa-solid fa-trash me-1"></i> ลบ
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10">
            
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
                <div class="card-header bg-white border-bottom border-light p-4 p-md-5 pb-4">
                    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                        <?php echo getPostTypeBadge($post['post_type']); ?>
                        <span class="badge bg-light text-secondary border px-3 py-2 fs-6"><i class="fa-solid fa-folder me-1"></i> <?php echo htmlspecialchars($post['category']); ?></span>
                    </div>

                    <h1 class="fw-bold text-dark mb-4" style="font-size: 2.2rem;"><?php echo htmlspecialchars($post['title']); ?></h1>

                    <div class="d-flex justify-content-between align-items-center flex-wrap bg-light rounded-4 p-3 border">
                        <div class="d-flex align-items-center">
                            <?php if (!empty($post['profile_picture'])): ?>
                                <img src="../../uploads/avatars/<?php echo htmlspecialchars($post['profile_picture']); ?>" class="rounded-circle shadow-sm me-3 object-fit-cover" width="50" height="50">
                            <?php else: ?>
                                <div class="rounded-circle bg-purple text-white d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; font-weight: bold;"><?php echo mb_substr($post['first_name'], 0, 1, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold text-purple mb-0"><?php echo htmlspecialchars($post['first_name'].' '.$post['last_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($post['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์'); ?></div>
                            </div>
                        </div>
                        <div class="text-end extra-small text-muted fw-bold">
                            <i class="fa-regular fa-calendar me-1"></i> <?php echo date('d M Y', strtotime($post['created_at'])); ?> | 
                            <i class="fa-solid fa-eye ms-2 me-1"></i> <?php echo number_format($post['view_count']); ?> | 
                            <i class="fa-solid fa-heart ms-2 me-1 text-danger"></i> <?php echo number_format($post['like_count']); ?>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4 p-md-5 pt-4">
                    <div class="km-content text-dark lh-lg mb-5">
                        <?php echo $post['content']; ?>
                    </div>

                    <?php if (!empty($attachments)): ?>
                        <div class="p-4 bg-light rounded-4 border border-light mb-4 d-print-none">
                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-paperclip me-2 text-primary"></i> เอกสารประกอบ (<?php echo count($attachments); ?>)</h6>
                            <div class="row g-3">
                                <?php foreach($attachments as $file): 
                                    $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                                    $icon = 'fa-file'; $color = 'text-secondary';
                                    if($ext == 'pdf') { $icon = 'fa-file-pdf'; $color = 'text-danger'; }
                                    elseif(in_array($ext, ['doc','docx'])) { $icon = 'fa-file-word'; $color = 'text-primary'; }
                                    elseif(in_array($ext, ['xls','xlsx'])) { $icon = 'fa-file-excel'; $color = 'text-success'; }
                                ?>
                                    <div class="col-md-6 col-lg-4">
                                        <a href="../../uploads/km_attachments/<?php echo htmlspecialchars($file['file_path']); ?>" download="<?php echo htmlspecialchars($file['file_name']); ?>" class="text-decoration-none">
                                            <div class="p-2 border rounded-3 bg-white d-flex align-items-center hover-lift">
                                                <i class="fa-solid <?php echo $icon.' '.$color; ?> fs-3 me-2"></i>
                                                <div class="overflow-hidden">
                                                    <div class="small fw-bold text-dark text-truncate"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                                    <div class="extra-small text-muted"><?php echo formatSize($file['file_size']); ?></div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($post['tags'])): ?>
                        <div class="d-flex flex-wrap gap-2 pt-4 border-top">
                            <?php foreach(explode(',', $post['tags']) as $tag): if(trim($tag)): ?>
                                <a href="index.php?search=<?php echo urlencode(trim($tag)); ?>" class="badge bg-light text-primary border text-decoration-none px-3 py-2 rounded-pill hover-purple-bg">#<?php echo htmlspecialchars(ltrim(trim($tag), '#')); ?></a>
                            <?php endif; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer bg-light border-0 p-4 text-center d-print-none">
                    <form action="" method="POST">
                        <button type="submit" name="toggle_like" class="btn btn-lg rounded-pill px-5 shadow-sm fw-bold <?php echo $has_liked ? 'btn-danger' : 'btn-white border text-danger'; ?> hover-lift">
                            <i class="fa-<?php echo $has_liked ? 'solid' : 'regular'; ?> fa-heart me-2"></i> <?php echo $has_liked ? 'คุณถูกใจสิ่งนี้' : 'ถูกใจบทความ'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <div id="comments_section" class="card border-0 shadow-sm rounded-4 bg-white mb-5 d-print-none">
                <div class="card-header bg-white border-bottom border-light p-4">
                    <h5 class="fw-bold mb-0"><i class="fa-regular fa-comments text-primary me-2"></i>แลกเปลี่ยนเรียนรู้ (<?php echo count($all_comments); ?>)</h5>
                </div>
                <div class="card-body p-4">
                    <?php foreach($all_comments as $cmt): ?>
                        <div class="d-flex mb-4">
                            <div class="me-3">
                                <?php if($cmt['profile_picture']): ?>
                                    <img src="../../uploads/avatars/<?php echo $cmt['profile_picture']; ?>" class="rounded-circle object-fit-cover shadow-sm" width="40" height="40">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 12px;"><?php echo mb_substr($cmt['first_name'],0,1,'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1 bg-light rounded-4 p-3 border border-light position-relative">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold small"><?php echo htmlspecialchars($cmt['first_name'].' '.$cmt['last_name']); ?></span>
                                    <span class="extra-small text-muted"><?php echo date('d/m/H:i', strtotime($cmt['created_at'])); ?></span>
                                </div>
                                <p class="small mb-0 text-dark"><?php echo nl2br(htmlspecialchars($cmt['comment_text'])); ?></p>
                                <?php if($cmt['user_id'] == $user_id || hasRole(['admin'])): ?>
                                    <a href="view.php?id=<?php echo $post_id; ?>&action=delete_comment&comment_id=<?php echo $cmt['comment_id']; ?>" class="text-danger extra-small position-absolute top-0 end-0 mt-2 me-2" onclick="return confirm('ลบคอมเมนต์?');"><i class="fa-solid fa-xmark"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <form action="view.php?id=<?php echo $post_id; ?>#comments_section" method="POST" class="mt-4 pt-3 border-top">
                        <textarea name="comment_text" class="form-control border-primary shadow-sm rounded-4 mb-2" rows="3" placeholder="ร่วมแชร์ประสบการณ์หรือสอบถามเพิ่มเติม..." required></textarea>
                        <div class="text-end"><button type="submit" name="add_comment" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">ส่งความคิดเห็น</button></div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .bg-purple { background-color: var(--msu-purple); }
    .btn-white { background-color: #fff; }
    .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important; }
    .hover-purple-bg:hover { background-color: var(--msu-purple) !important; color: white !important; }
    .km-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 10px 0; }
    .km-content table { width: 100% !important; border-collapse: collapse; margin-bottom: 1rem; }
    .km-content table td, .km-content table th { border: 1px solid #dee2e6; padding: 8px; }
    @media print { .d-print-none { display: none !important; } .card { border: none !important; box-shadow: none !important; } }
</style>

<?php require_once '../../includes/footer.php'; ?>