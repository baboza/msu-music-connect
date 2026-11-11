<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

// รับ user_id ที่ต้องการดู (ถ้าไม่มีให้ดูของตัวเอง)
$target_user_id = isset($_GET['uid']) ? $_GET['uid'] : $user_id;

// ดึงข้อมูลพื้นฐานผู้ใช้
$stmt_user = $pdo->prepare("SELECT u.*, d.dept_name FROM users u LEFT JOIN departments d ON u.dept_id = d.dept_id WHERE u.user_id = ?");
$stmt_user->execute([$target_user_id]);
$profile = $stmt_user->fetch();

if (!$profile) {
    echo "<div class='container mt-5 text-center'><h5>ไม่พบข้อมูลประวัติ</h5></div>";
    exit;
}

// ดึงการศึกษา
$stmt_edu = $pdo->prepare("SELECT * FROM cv_education WHERE user_id = ? ORDER BY grad_year DESC");
$stmt_edu->execute([$target_user_id]);
$educations = $stmt_edu->fetchAll();

// ดึงประสบการณ์
$stmt_exp = $pdo->prepare("SELECT * FROM cv_experience WHERE user_id = ? ORDER BY exp_id DESC");
$stmt_exp->execute([$target_user_id]);
$experiences = $stmt_exp->fetchAll();

// ดึงทักษะ
$stmt_skill = $pdo->prepare("SELECT * FROM cv_skills WHERE user_id = ?");
$stmt_skill->execute([$target_user_id]);
$skills = $stmt_skill->fetchAll();

// ---------------- เพิ่มเติม ----------------
$stmt_certs = $pdo->prepare("SELECT * FROM cv_certificates WHERE user_id = ? ORDER BY issue_date DESC");
$stmt_certs->execute([$target_user_id]);
$certs = $stmt_certs->fetchAll();

$stmt_langs = $pdo->prepare("SELECT * FROM cv_languages WHERE user_id = ?");
$stmt_langs->execute([$target_user_id]);
$langs = $stmt_langs->fetchAll();

$stmt_research = $pdo->prepare("SELECT * FROM cv_research WHERE user_id = ? ORDER BY publish_year DESC");
$stmt_research->execute([$target_user_id]);
$research = $stmt_research->fetchAll();

$stmt_grants = $pdo->prepare("SELECT * FROM cv_grants WHERE user_id = ? ORDER BY grant_year DESC");
$stmt_grants->execute([$target_user_id]);
$grants = $stmt_grants->fetchAll();

$stmt_awards = $pdo->prepare("SELECT * FROM cv_awards WHERE user_id = ? ORDER BY award_year DESC");
$stmt_awards->execute([$target_user_id]);
$awards = $stmt_awards->fetchAll();

$stmt_advisors = $pdo->prepare("SELECT * FROM cv_advisor WHERE user_id = ? ORDER BY adv_year DESC");
$stmt_advisors->execute([$target_user_id]);
$advisors = $stmt_advisors->fetchAll();

$stmt_committees = $pdo->prepare("SELECT * FROM cv_committee WHERE user_id = ? ORDER BY cmt_year DESC");
$stmt_committees->execute([$target_user_id]);
$committees = $stmt_committees->fetchAll();

$stmt_courses = $pdo->prepare("SELECT * FROM cv_courses WHERE user_id = ? ORDER BY course_name ASC");
$stmt_courses->execute([$target_user_id]);
$courses = $stmt_courses->fetchAll();
?>

<div class="mt-2 mb-4">
    <!-- Sub-page Command Bar Header -->
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="mobile-back-btn me-2" hx-boost="true" title="กลับหน้าหลัก">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="flex-grow-1">
            <h5 class="fw-bold mb-0 text-dark">ประวัติและผลงาน</h5>
            <small class="text-muted">e-Portfolio & Academic CV</small>
        </div>
        <div class="app-icon-container">
            <i class="fa-solid fa-file-invoice"></i>
        </div>
    </div>

    <!-- Profile Header -->
    <div class="bento-box p-4 text-center mb-3">
        <div class="position-relative d-inline-block mb-3">
            <?php if (!empty($profile['profile_picture'])): ?>
                <img src="../uploads/avatars/<?= htmlspecialchars($profile['profile_picture']) ?>" class="rounded-circle shadow-sm" width="96" height="96" style="border: 3px solid var(--primary-light); object-fit: cover;">
            <?php else: ?>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($profile['first_name'].' '.$profile['last_name']) ?>&background=35005e&color=fff&size=96" alt="Profile" class="rounded-circle shadow-sm" style="border: 3px solid var(--primary-light);">
            <?php endif; ?>
        </div>
        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h5>
        <p class="text-purple fw-bold small mb-1"><?= htmlspecialchars($profile['position'] ?? 'บุคลากร') ?></p>
        <p class="text-muted small mb-0"><i class="fa-solid fa-building me-1"></i><?= htmlspecialchars($profile['dept_name'] ?? 'วิทยาลัยดุริยางคศิลป์') ?></p>
        <?php if (!empty($profile['phone'])): ?>
        <p class="text-muted extra-small mb-0 mt-1"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($profile['phone']) ?></p>
        <?php endif; ?>
    </div>

    <!-- Education Timeline -->
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-graduation-cap text-purple me-2"></i>ประวัติการศึกษา</h6>
        <?php if (empty($educations)): ?>
            <p class="text-muted small text-center mb-0 py-2">ไม่มีข้อมูลประวัติการศึกษา</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($educations as $edu): ?>
                <div class="timeline-item pb-3 mb-3 border-bottom last-child-no-border">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="fw-bold text-dark mb-0 fs-6"><?= htmlspecialchars($edu['degree_name']) ?></h6>
                        <span class="badge bg-purple-subtle text-purple border border-purple-subtle"><?= htmlspecialchars($edu['grad_year']) ?></span>
                    </div>
                    <small class="text-muted"><?= htmlspecialchars($edu['institution']) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Experience Timeline -->
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-briefcase text-purple me-2"></i>ประสบการณ์ทำงาน</h6>
        <?php if (empty($experiences)): ?>
            <p class="text-muted small text-center mb-0 py-2">ไม่มีข้อมูลประสบการณ์</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($experiences as $exp): ?>
                <div class="timeline-item pb-3 mb-3 border-bottom last-child-no-border">
                    <h6 class="fw-bold text-dark mb-1 fs-6"><?= htmlspecialchars($exp['job_title']) ?></h6>
                    <small class="text-purple fw-bold d-block mb-1"><?= htmlspecialchars($exp['organization']) ?></small>
                    <small class="text-muted d-block mb-1"><i class="fa-regular fa-calendar me-1"></i><?= htmlspecialchars($exp['duration']) ?></small>
                    <?php if (!empty($exp['description'])): ?>
                        <div class="small text-muted mt-2 p-2 bg-light rounded-3" style="font-size: 0.8rem; line-height: 1.5;">
                            <?= nl2br(htmlspecialchars($exp['description'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Skills -->
    <?php if (!empty($skills)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-star text-purple me-2"></i>ทักษะความสามารถ</h6>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($skills as $skill): ?>
                <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-2 fw-medium">
                    <?= htmlspecialchars($skill['skill_name']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Languages -->
    <?php if (!empty($langs)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-language text-purple me-2"></i>ภาษา (Languages)</h6>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($langs as $lang): ?>
                <span class="badge bg-slate-100 text-slate-700 border rounded-pill px-3 py-2 fw-medium">
                    <?= htmlspecialchars($lang['language_name']) ?> (<?= htmlspecialchars($lang['proficiency'] ?? '') ?>)
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Research -->
    <?php if (!empty($research)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-microscope text-purple me-2"></i>ผลงานวิจัย / ตีพิมพ์</h6>
        <div class="timeline">
            <?php foreach ($research as $res): ?>
            <div class="timeline-item pb-3 mb-3 border-bottom last-child-no-border">
                <div class="small text-dark mb-1" style="line-height: 1.5; font-size: 0.85rem;">
                    <?= $res['citation_text'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Grants -->
    <?php if (!empty($grants)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-hand-holding-dollar text-purple me-2"></i>ทุนวิจัยที่ได้รับ</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($grants as $gr): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border">
                    <i class="fa-solid fa-check text-success me-2"></i><strong><?= htmlspecialchars($gr['grant_name']) ?></strong>
                    <?php if($gr['grant_source']) echo "<br><span class='text-muted ms-4'>แหล่งทุน: " . htmlspecialchars($gr['grant_source']) . "</span>"; ?>
                    <span class="text-muted ms-1">(<?= htmlspecialchars($gr['grant_year']) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Awards -->
    <?php if (!empty($awards)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-trophy text-purple me-2"></i>รางวัลเกียรติยศ (Awards)</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($awards as $aw): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border">
                    <i class="fa-solid fa-award text-warning me-2"></i><strong><?= htmlspecialchars($aw['award_name']) ?></strong>
                    <?php if($aw['award_org']) echo "<br><span class='text-muted ms-4'>มอบโดย: " . htmlspecialchars($aw['award_org']) . "</span>"; ?>
                    <span class="text-muted ms-1">(<?= htmlspecialchars($aw['award_year']) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Teaching Courses -->
    <?php if (!empty($courses)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-chalkboard-user text-purple me-2"></i>รายวิชาที่สอน</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($courses as $c): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border">
                    <i class="fa-solid fa-book-open text-purple me-2"></i>
                    <?php if($c['course_code']) echo "<strong>" . htmlspecialchars($c['course_code']) . "</strong> "; ?>
                    <?= htmlspecialchars($c['course_name']) ?>
                    <div class="text-muted ms-4" style="font-size: 0.75rem;">ระดับ: <?= htmlspecialchars($c['course_level']) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Thesis Advisor -->
    <?php if (!empty($advisors)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-user-graduate text-purple me-2"></i>ที่ปรึกษาวิทยานิพนธ์</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($advisors as $adv): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border" style="line-height:1.5;">
                    <strong class="text-purple"><?= htmlspecialchars($adv['adv_type']) ?>:</strong> <?= htmlspecialchars($adv['student_name']) ?>.<br>
                    <i class="text-muted ms-2">"<?= htmlspecialchars($adv['thesis_title']) ?>"</i> (<?= htmlspecialchars($adv['adv_year']) ?>)
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Thesis Committee -->
    <?php if (!empty($committees)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-users text-purple me-2"></i>กรรมการสอบวิทยานิพนธ์</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($committees as $cmt): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border" style="line-height:1.5;">
                    <i class="fa-solid fa-caret-right text-muted me-1"></i><?= htmlspecialchars($cmt['student_name']) ?>.<br>
                    <i class="text-muted ms-3">"<?= htmlspecialchars($cmt['thesis_title']) ?>"</i> (<?= htmlspecialchars($cmt['cmt_year']) ?>)
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Certificates -->
    <?php if (!empty($certs)): ?>
    <div class="bento-box p-4 mb-3">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-certificate text-purple me-2"></i>ใบรับรอง (Certificates)</h6>
        <ul class="list-unstyled small text-dark mb-0">
            <?php foreach ($certs as $crt): ?>
                <li class="mb-2 pb-2 border-bottom last-child-no-border">
                    <strong><?= htmlspecialchars($crt['cert_name']) ?></strong><br>
                    <span class="text-muted"><i class="fa-regular fa-building me-1"></i><?= htmlspecialchars($crt['issuing_org']) ?></span>
                    <span class="text-muted ms-2">(<?= htmlspecialchars($crt['issue_date']) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($target_user_id == $user_id): ?>
    <div class="text-center mt-3 mb-4">
        <p class="text-muted extra-small mb-0"><i class="fa-solid fa-circle-info me-1"></i>หากต้องการเพิ่มหรือแก้ไขข้อมูล CV กรุณาทำรายการผ่านระบบเวอร์ชัน PC</p>
    </div>
    <?php endif; ?>

    <div style="height: 60px;"></div>
</div>

<style>
    .last-child-no-border:last-child {
        border-bottom: none !important;
        padding-bottom: 0 !important;
        margin-bottom: 0 !important;
    }
</style>

<?php require_once 'includes/footer.php'; ?>
