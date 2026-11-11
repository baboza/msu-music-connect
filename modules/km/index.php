<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];

// =========================================================
// 1. รับค่าจากการค้นหาและตัวกรอง (Filters)
// =========================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : '';

// =========================================================
// 2. Query ดึงข้อมูลโพสต์ความรู้
// =========================================================
$sql = "SELECT k.*, u.first_name, u.last_name, u.profile_picture, d.dept_name 
        FROM km_posts k
        JOIN users u ON k.user_id = u.user_id
        LEFT JOIN departments d ON u.dept_id = d.dept_id
        WHERE k.status = 'published'";

$params = [];

if (!empty($search)) {
    // เปลี่ยน :search ให้เป็น 1, 2, 3 ไม่ให้ซ้ำกัน
    $sql .= " AND (k.title LIKE :search1 OR k.tags LIKE :search2 OR k.content LIKE :search3)";
    $search_term = "%$search%";
    $params[':search1'] = $search_term;
    $params[':search2'] = $search_term;
    $params[':search3'] = $search_term;
}

if ($filter_type !== 'all') {
    $sql .= " AND k.post_type = :type";
    $params[':type'] = $filter_type;
}
if (!empty($filter_category)) {
    $sql .= " AND k.category = :category";
    $params[':category'] = $filter_category;
}

$sql .= " ORDER BY k.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// 3. ดึงสถิติภาพรวม (KPIs)
// =========================================================
$total_posts = $pdo->query("SELECT COUNT(*) FROM km_posts WHERE status='published'")->fetchColumn();
$total_best = $pdo->query("SELECT COUNT(*) FROM km_posts WHERE post_type='best_practice' AND status='published'")->fetchColumn();
$top_contributor = $pdo->query("
    SELECT u.first_name, u.last_name, COUNT(k.post_id) as cnt 
    FROM km_posts k JOIN users u ON k.user_id = u.user_id 
    WHERE k.status='published' GROUP BY k.user_id ORDER BY cnt DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันแปลงประเภทโพสต์เป็น Badge สวยๆ สไตล์ Soft UI
function getPostTypeBadge($type) {
    if ($type == 'best_practice') return '<span class="badge bg-warning-subtle text-dark border border-warning shadow-sm px-3 py-2"><i class="fa-solid fa-star me-1 text-danger"></i> Best Practice</span>';
    if ($type == 'lesson_learned') return '<span class="badge bg-info-subtle text-info border border-info shadow-sm px-3 py-2"><i class="fa-solid fa-lightbulb me-1"></i> Lesson Learned</span>';
    return '<span class="badge bg-success-subtle text-success border border-success shadow-sm px-3 py-2"><i class="fa-solid fa-share-nodes me-1"></i> Knowledge Sharing</span>';
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-solid fa-brain me-2"></i>Knowledge Management (KM)</h1>
            <p class="text-muted small mb-0">ศูนย์กลางรวบรวม ถ่ายทอด และต่อยอดองค์ความรู้ของวิทยาลัย</p>
        </div>
        <div class="d-flex gap-2 mt-3 mt-md-0">
            <a href="create.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-pen-nib me-1"></i> แบ่งปันความรู้
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary me-4">
                        <i class="fa-solid fa-book-open"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">คลังความรู้ทั้งหมด</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($total_posts); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning me-4">
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">Best Practice</h6>
                        <h3 class="fw-bold text-dark mb-0"><?php echo number_format($total_best); ?> <span class="fs-6 text-muted fw-normal">รายการ</span></h3>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm bg-white">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="stat-icon bg-success-subtle text-success me-4">
                        <i class="fa-solid fa-medal"></i>
                    </div>
                    <div>
                        <h6 class="text-muted fw-bold text-uppercase mb-1 extra-small">Top Contributor</h6>
                        <h5 class="fw-bold text-dark mb-0 text-truncate" style="max-width: 150px;">
                            <?php echo $top_contributor ? htmlspecialchars($top_contributor['first_name'].' '.mb_substr($top_contributor['last_name'],0,1).'.') : 'ยังไม่มีข้อมูล'; ?>
                        </h5>
                        <div class="extra-small text-muted mt-1"><?php echo $top_contributor ? $top_contributor['cnt'] . ' โพสต์ความรู้' : '-'; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm bg-white mb-5">
        <div class="card-body p-4">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold small text-muted">ค้นหาหัวข้อ / แท็ก (Hashtag)</label>
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                        <span class="input-group-text bg-light border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-0 bg-light shadow-none" placeholder="เช่น #EdPEx, Excel, การสอน..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-muted">หมวดหมู่งาน</label>
                    <select name="category" class="form-select border-light bg-light shadow-sm rounded-pill">
                        <option value="">-- ทุกหมวดหมู่ --</option>
                        <option value="วิชาการและการสอน" <?php echo $filter_category == 'วิชาการและการสอน' ? 'selected' : ''; ?>>วิชาการและการสอน</option>
                        <option value="วิจัยและนวัตกรรม" <?php echo $filter_category == 'วิจัยและนวัตกรรม' ? 'selected' : ''; ?>>วิจัยและนวัตกรรม</option>
                        <option value="บริหารและการจัดการ" <?php echo $filter_category == 'บริหารและการจัดการ' ? 'selected' : ''; ?>>บริหารและการจัดการ</option>
                        <option value="กิจการนิสิต" <?php echo $filter_category == 'กิจการนิสิต' ? 'selected' : ''; ?>>กิจการนิสิต</option>
                        <option value="เทคโนโลยีและระบบ" <?php echo $filter_category == 'เทคโนโลยีและระบบ' ? 'selected' : ''; ?>>เทคโนโลยีและระบบ</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark fw-bold px-4 w-100 shadow-sm rounded-pill">กรองข้อมูล</button>
                </div>
            </form>

            <div class="d-flex flex-wrap gap-2 mt-4 pt-4 border-top border-light">
                <a href="?type=all&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filter_category); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_type == 'all' ? 'btn-dark shadow-sm' : 'btn-light border text-muted'; ?>">
                    ทั้งหมด
                </a>
                <a href="?type=best_practice&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filter_category); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_type == 'best_practice' ? 'btn-warning shadow-sm text-dark' : 'btn-light border text-muted'; ?>">
                    <i class="fa-solid fa-star me-1 text-warning"></i> Best Practice
                </a>
                <a href="?type=lesson_learned&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filter_category); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_type == 'lesson_learned' ? 'btn-info shadow-sm text-white' : 'btn-light border text-muted'; ?>">
                    <i class="fa-solid fa-lightbulb me-1 text-info"></i> Lesson Learned
                </a>
                <a href="?type=knowledge_sharing&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($filter_category); ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?php echo $filter_type == 'knowledge_sharing' ? 'btn-success shadow-sm text-white' : 'btn-light border text-muted'; ?>">
                    <i class="fa-solid fa-share-nodes me-1 text-success"></i> Knowledge Sharing
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <?php if (empty($posts)): ?>
            <div class="col-12 text-center py-5">
                <div class="stat-icon bg-secondary-subtle text-secondary mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <h4 class="fw-bold text-muted">ไม่พบองค์ความรู้ที่ค้นหา</h4>
                <p class="text-muted">ลองเปลี่ยนคำค้นหา หรือเป็นคนแรกที่แบ่งปันความรู้ในหัวข้อนี้สิครับ!</p>
                <a href="create.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold mt-2">เขียนบทความใหม่</a>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm bg-white" style="transition: all 0.3s ease;">
                        <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center">
                                <?php if (!empty($post['profile_picture'])): ?>
                                    <img src="../../uploads/avatars/<?php echo htmlspecialchars($post['profile_picture']); ?>" class="rounded-circle object-fit-cover shadow-sm me-3" width="45" height="45">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center shadow-sm me-3" style="width: 45px; height: 45px; font-size: 16px; font-weight: bold;">
                                        <?php echo mb_substr($post['first_name'], 0, 1, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-bold text-dark small mb-0 lh-1"><?php echo htmlspecialchars($post['first_name'].' '.$post['last_name']); ?></div>
                                    <div class="extra-small text-muted mt-1"><?php echo htmlspecialchars($post['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body px-4 py-3 d-flex flex-column">
                            <div class="mb-3 mt-1">
                                <?php echo getPostTypeBadge($post['post_type']); ?>
                            </div>
                            
                            <a href="view.php?id=<?php echo $post['post_id']; ?>" class="text-decoration-none">
                                <h5 class="fw-bold text-dark mb-2" style="transition: color 0.2s;" onmouseover="this.style.color='#4b0082';" onmouseout="this.style.color='#2d3748';">
                                    <?php echo htmlspecialchars($post['title']); ?>
                                </h5>
                            </a>
                            
                            <p class="text-muted small flex-grow-1" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6;">
                                <?php 
                                    $snippet = strip_tags($post['content']);
                                    echo mb_strlen($snippet, 'UTF-8') > 120 ? mb_substr($snippet, 0, 120, 'UTF-8') . '...' : $snippet;
                                ?>
                            </p>

                            <?php if (!empty($post['tags'])): ?>
                                <div class="d-flex flex-wrap gap-2 mb-2 mt-2">
                                    <?php 
                                        $tags = explode(',', $post['tags']);
                                        foreach($tags as $tag): 
                                            $tag = trim($tag);
                                            if(!empty($tag)):
                                    ?>
                                        <a href="?search=<?php echo urlencode($tag); ?>" class="badge bg-light text-primary border text-decoration-none fw-normal px-2 py-1" style="transition: all 0.2s;" onmouseover="this.className='badge bg-primary text-white border-primary text-decoration-none px-2 py-1';" onmouseout="this.className='badge bg-light text-primary border text-decoration-none px-2 py-1';">
                                            <?php echo htmlspecialchars(strpos($tag, '#') === 0 ? $tag : '#'.$tag); ?>
                                        </a>
                                    <?php endif; endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer bg-white border-top border-light px-4 py-3 d-flex justify-content-between align-items-center">
                            <div class="extra-small text-muted fw-bold">
                                <i class="fa-regular fa-clock me-1"></i> <?php echo date('d/m/Y', strtotime($post['created_at'])); ?>
                            </div>
                            <div class="d-flex gap-3 text-muted small fw-bold">
                                <span title="ยอดเข้าชม"><i class="fa-regular fa-eye me-1"></i> <?php echo number_format($post['view_count']); ?></span>
                                <span class="text-danger" title="ยอดถูกใจ"><i class="fa-regular fa-heart me-1"></i> <?php echo number_format($post['like_count']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<?php require_once '../../includes/footer.php'; ?>