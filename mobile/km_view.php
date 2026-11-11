<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($post_id == 0) {
    echo "<div class='container mt-5 text-center'><h5>ไม่พบข้อมูล</h5></div>";
    exit;
}

// อัปเดตยอดเข้าชม
$pdo->exec("UPDATE km_posts SET view_count = view_count + 1 WHERE post_id = $post_id");

// ดึงบทความ KM
$sql = "SELECT k.*, u.first_name, u.last_name, u.profile_picture 
        FROM km_posts k
        JOIN users u ON k.user_id = u.user_id
        WHERE k.post_id = ? AND k.status = 'published'";

$stmt = $pdo->prepare($sql);
$stmt->execute([$post_id]);
$post = $stmt->fetch();

if (!$post) {
    echo "<div class='container mt-5 text-center'><h5>ไม่พบบทความ หรือบทความถูกซ่อน</h5></div>";
    exit;
}

function getKmTypeBadge($type) {
    if($type == 'knowledge_sharing') return ['label' => 'แบ่งปันความรู้', 'class' => 'primary'];
    if($type == 'lesson_learned') return ['label' => 'บทเรียนที่ได้รับ', 'class' => 'warning'];
    if($type == 'best_practice') return ['label' => 'แนวปฏิบัติที่ดี', 'class' => 'success'];
    return ['label' => 'ทั่วไป', 'class' => 'secondary'];
}
$badge = getKmTypeBadge($post['post_type']);
?>

<div class="mt-2 mb-4">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="km.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าคลังความรู้">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">บทความองค์ความรู้</h5>
            <small class="text-muted">คลัง KM คณะ</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-brain"></i>
        </div>
    </div>

    <!-- Article Hero Banner -->
    <div class="bento-box p-4 mb-3 text-white position-relative overflow-hidden" style="background: var(--primary-gradient); border: 1px solid rgba(255,255,255,0.15) !important;">
        <span class="badge bg-white text-purple mb-2 fw-bold"><?= $badge['label'] ?></span>
        <h4 class="text-white fw-bold mb-0" style="line-height: 1.4;"><?= htmlspecialchars($post['title']) ?></h4>
    </div>

    <!-- Content Card -->
    <div class="bento-box p-4 mb-4">
        <!-- Author Info -->
        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
            <div class="d-flex align-items-center">
                <?php if (!empty($post['profile_picture'])): ?>
                    <img src="../uploads/avatars/<?= htmlspecialchars($post['profile_picture']) ?>" class="rounded-circle me-3 border shadow-sm" width="44" height="44" style="object-fit: cover;">
                <?php else: ?>
                    <div class="app-icon-container me-3" style="width:44px;height:44px;">
                        <i class="fa-solid fa-user fs-6"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($post['first_name'] . ' ' . $post['last_name']) ?></h6>
                    <small class="text-muted" style="font-size: 0.75rem;"><?= date('d M Y', strtotime($post['created_at'])) ?> • <i class="fa-regular fa-eye ms-1 me-1"></i> <?= number_format($post['view_count']) ?> views</small>
                </div>
            </div>
            <button class="btn btn-outline-primary btn-sm rounded-pill px-3">
                <i class="fa-regular fa-thumbs-up me-1"></i>ถูกใจ
            </button>
        </div>

        <!-- Content -->
        <div class="km-content text-dark" style="line-height: 1.8; font-size: 0.95rem; overflow-wrap: break-word;">
            <?= $post['content'] ?>
        </div>

        <?php if (!empty($post['tags'])): ?>
        <div class="mt-4 pt-3 border-top">
            <h6 class="fw-bold text-muted small mb-2"><i class="fa-solid fa-tags me-1 text-purple"></i>แท็กที่เกี่ยวข้อง:</h6>
            <div class="d-flex flex-wrap gap-2">
                <?php 
                $tags = explode(',', $post['tags']);
                foreach ($tags as $tag): 
                    $t = trim($tag);
                    if(!empty($t)):
                ?>
                    <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1 fw-medium">#<?= htmlspecialchars($t) ?></span>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div style="height: 60px;"></div>
</div>

<?php require_once 'includes/footer.php'; ?>
