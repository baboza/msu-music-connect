<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$search = $_GET['q'] ?? '';

// ดึงบทความ KM
$sql = "SELECT k.*, u.first_name, u.last_name, u.profile_picture 
        FROM km_posts k
        JOIN users u ON k.user_id = u.user_id
        WHERE k.status = 'published'";

$params = [];
if (!empty($search)) {
    $sql .= " AND (k.title LIKE ? OR k.tags LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY k.created_at DESC LIMIT 30";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

function getKmTypeBadge($type) {
    if($type == 'knowledge_sharing') return ['label' => 'แบ่งปันความรู้', 'class' => 'primary'];
    if($type == 'lesson_learned') return ['label' => 'บทเรียนที่ได้รับ', 'class' => 'warning'];
    if($type == 'best_practice') return ['label' => 'แนวปฏิบัติที่ดี', 'class' => 'success'];
    return ['label' => 'ทั่วไป', 'class' => 'secondary'];
}
?>

<div class="mt-2 mb-4">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">แหล่งเรียนรู้ (KM)</h5>
            <small class="text-muted">คลังความรู้และแนวปฏิบัติที่ดีขององค์กร</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-brain"></i>
        </div>
    </div>

    <!-- Search Box -->
    <div class="mb-3">
        <form action="km.php" method="GET" class="position-relative">
            <input type="text" name="q" class="form-control rounded-pill ps-4 py-2 shadow-sm border bg-white" placeholder="ค้นหาบทความ, องค์ความรู้..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn position-absolute top-50 end-0 translate-middle-y text-muted me-2 border-0 bg-transparent">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </form>
    </div>

    <?php if (empty($posts)): ?>
        <div class="text-center py-5 bento-box">
            <div class="app-icon-container mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.6rem;">
                <i class="fa-solid fa-book-open"></i>
            </div>
            <h6 class="fw-bold text-dark">ไม่พบบทความ</h6>
            <p class="text-muted small mb-0">ลองค้นหาด้วยคำอื่นดูอีกครั้ง</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($posts as $post): 
                $badge = getKmTypeBadge($post['post_type']);
            ?>
            <div class="col-12">
                <a href="km_view.php?id=<?= $post['post_id'] ?>" class="text-decoration-none" hx-boost="true">
                    <div class="bento-box p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-<?= $badge['class'] ?>-subtle text-<?= $badge['class'] ?> rounded-pill extra-small">
                                <?= $badge['label'] ?>
                            </span>
                            <span class="text-muted extra-small"><i class="fa-regular fa-eye me-1"></i><?= number_format($post['view_count']) ?></span>
                        </div>
                        <h6 class="fw-bold text-dark mb-2" style="line-height: 1.4;"><?= htmlspecialchars($post['title']) ?></h6>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                            <div class="d-flex align-items-center">
                                <?php if (!empty($post['profile_picture'])): ?>
                                    <img src="../uploads/avatars/<?= htmlspecialchars($post['profile_picture']) ?>" class="rounded-circle me-2" width="24" height="24" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-secondary bg-opacity-25 rounded-circle d-flex justify-content-center align-items-center me-2 text-dark" style="width:24px;height:24px;font-size:10px;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <small class="text-muted extra-small"><?= htmlspecialchars($post['first_name'] . ' ' . $post['last_name']) ?></small>
                            </div>
                            <small class="text-muted extra-small"><?= date('d/m/Y', strtotime($post['created_at'])) ?></small>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
