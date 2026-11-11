<?php
// ไฟล์นี้เปิดแบบ Public ไม่ต้องเช็ค Session
require_once '../../config/db.php';

$u_id = isset($_GET['u']) ? $_GET['u'] : '';
if (empty($u_id)) die("<div style='text-align:center; padding:50px;'><h2>ไม่พบรหัสผู้ใช้งาน</h2></div>");

// 1. ดึงข้อมูลพื้นฐานบุคลากร
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$u_id]);
$user = $stmt->fetch();
if (!$user) die("<div style='text-align:center; padding:50px;'><h2>ไม่พบข้อมูลบุคลากรในระบบ</h2></div>");

// 2. ดึงข้อมูลประวัติทั้ง 11 ส่วน
$edus = $pdo->prepare("SELECT * FROM cv_education WHERE user_id = ? ORDER BY grad_year DESC"); $edus->execute([$u_id]);
$exps = $pdo->prepare("SELECT * FROM cv_experience WHERE user_id = ? ORDER BY duration DESC"); $exps->execute([$u_id]);
$skills = $pdo->prepare("SELECT * FROM cv_skills WHERE user_id = ? ORDER BY skill_level DESC"); $skills->execute([$u_id]);
$certs = $pdo->prepare("SELECT * FROM cv_certificates WHERE user_id = ? ORDER BY issue_date DESC"); $certs->execute([$u_id]);
$langs = $pdo->prepare("SELECT * FROM cv_languages WHERE user_id = ?"); $langs->execute([$u_id]);
$research = $pdo->prepare("SELECT * FROM cv_research WHERE user_id = ? ORDER BY publish_year DESC"); $research->execute([$u_id]);
$grants = $pdo->prepare("SELECT * FROM cv_grants WHERE user_id = ? ORDER BY grant_year DESC"); $grants->execute([$u_id]);
$awards = $pdo->prepare("SELECT * FROM cv_awards WHERE user_id = ? ORDER BY award_year DESC"); $awards->execute([$u_id]);
$advisors = $pdo->prepare("SELECT * FROM cv_advisor WHERE user_id = ? ORDER BY adv_year DESC"); $advisors->execute([$u_id]);
$committees = $pdo->prepare("SELECT * FROM cv_committee WHERE user_id = ? ORDER BY cmt_year DESC"); $committees->execute([$u_id]);
$courses = $pdo->prepare("SELECT * FROM cv_courses WHERE user_id = ? ORDER BY course_name ASC"); $courses->execute([$u_id]);

$base_url = "/music-e-office";
$auto_print = isset($_GET['print']) ? 'true' : 'false';

// -------------------------------------------------------------
// จัดการข้อมูลแสดงผลบนหัวกระดาษ (ชื่อ ENG นำ ตามด้วย TH)
// -------------------------------------------------------------

// 1. จัดการชื่อ-นามสกุล และคำนำหน้า (ภาษาไทย)
$prefix_th = htmlspecialchars($user['prefix_name'] ?? '');
$name_th = htmlspecialchars($user['first_name'] . " " . $user['last_name']);
$full_name_th = trim($prefix_th . " " . $name_th);

// 2. จัดการชื่อ-นามสกุล และคำนำหน้า (ภาษาอังกฤษ)
$prefix_en = htmlspecialchars($user['prefix_name_en'] ?? '');
$name_en = trim(htmlspecialchars($user['first_name_en'] ?? '') . " " . htmlspecialchars($user['last_name_en'] ?? ''));

// กำหนดชื่อที่จะแสดง
if (!empty($name_en)) {
    $main_name = !empty($prefix_en) ? $prefix_en . " " . $name_en : $name_en;
    $sub_name = $full_name_th;
} else {
    $main_name = $full_name_th;
    $sub_name = "";
}

// 3. จัดการตำแหน่งงาน
$position_th = htmlspecialchars($user['position'] ?? 'บุคลากรประจำคณะ');
$position_en = htmlspecialchars($user['position_en'] ?? '');

if (!empty($position_en)) {
    $main_position = $position_en;
    $sub_position = $position_th;
} else {
    $main_position = $position_th;
    $sub_position = "";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>CV - <?php echo htmlspecialchars($main_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&display=swap');
        
        :root { --purple: #39048f; --gold: #b0914f; }
        body { background: #f0f2f5; font-family: 'Anuphan', sans-serif; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; }

        .page { width: 210mm; min-height: 297mm; padding: 20mm; margin: 20px auto; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; }
        .cv-header { border-bottom: 4px solid var(--purple); padding-bottom: 15px; margin-bottom: 30px; }
        .college-logo { height: 85px; width: auto; }
        
        .profile-pic { width: 140px; height: 170px; object-fit: cover; border: 4px solid white; box-shadow: 0 3px 10px rgba(0,0,0,0.1); background: #f8f9fa; }
        .section-title { color: var(--purple); border-left: 6px solid var(--gold); padding: 6px 12px; font-weight: bold; font-size: 1.1rem; margin: 25px 0 15px 0; background: #fcfaff; text-transform: uppercase; }
        .info-row { font-size: 0.95rem; margin-bottom: 10px; border-bottom: 1px solid #fcfcfc; padding-bottom: 6px; }
        .text-purple { color: var(--purple) !important; }
        .text-gold { color: var(--gold) !important; }

        /* สไตล์สำหรับ APA format */
        .apa-citation { padding-left: 30px; text-indent: -30px; line-height: 1.6; font-size: 0.95rem; margin-bottom: 15px; color: #333; }
        .apa-citation a { color: var(--purple); text-decoration: none; word-break: break-all; }
        .apa-citation a:hover { text-decoration: underline; }
        
        .bullet-list { padding-left: 20px; font-size: 0.95rem; margin-bottom: 15px; }
        .bullet-list li { margin-bottom: 8px; }

        @media print {
            body { background: none; padding: 0; }
            .page { margin: 0; border: none; box-shadow: none; width: 100%; padding: 15mm 20mm; }
            .no-print { display: none !important; }
            a { text-decoration: none !important; color: #000 !important; }
            .section-title { page-break-after: avoid; }
            .info-row, .apa-citation, .bullet-list li { page-break-inside: avoid; }
        }
    </style>
</head>
<body onload="<?php if($auto_print == 'true') echo 'setTimeout(function(){ window.print(); }, 500);'; ?>">

<div class="no-print p-3 text-center bg-dark d-flex justify-content-center gap-2">
    <button onclick="window.print()" class="btn btn-warning rounded-pill px-4 fw-bold">
        <i class="fa-solid fa-print me-2"></i> พิมพ์ประวัติ (A4)
    </button>
    <a href="index.php" class="btn btn-outline-light rounded-pill px-4">
        <i class="fa-solid fa-arrow-left me-2"></i> กลับหน้าจัดการ
    </a>
</div>

<div class="page">
    <div class="cv-header d-flex justify-content-between align-items-center">
        <img src="<?php echo $base_url; ?>/uploads/logo.png" class="college-logo" alt="MSU Music Logo" onerror="this.style.display='none'">
        <div class="text-end">
            <h3 class="fw-bold text-purple mb-0">CURRICULUM VITAE</h3>
            <p class="text-dark small fw-bold mb-0 mt-1">College of Music, Mahasarakham University. Thailand</p>
            <p class="text-muted small mb-0">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-8">
            <h2 class="fw-bold text-dark mb-0"><?php echo $main_name; ?></h2>
            <?php if(!empty($sub_name)): ?>
                <h5 class="text-secondary fw-normal mb-1">(<?php echo $sub_name; ?>)</h5>
            <?php endif; ?>
            
            <h5 class="text-purple fw-bold mb-0 mt-3"><?php echo $main_position; ?></h5>
            <?php if(!empty($sub_position)): ?>
                <p class="text-muted fw-normal mb-3"><?php echo $sub_position; ?></p>
            <?php else: ?>
                <div class="mb-4"></div>
            <?php endif; ?>
            
            <div class="contact-info small text-dark mt-2">
                <p class="mb-2"><i class="fa-solid fa-envelope me-2 text-purple" style="width: 15px;"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                <p class="mb-2"><i class="fa-solid fa-phone me-2 text-purple" style="width: 15px;"></i> <?php echo htmlspecialchars($user['phone'] ?? '-'); ?></p>
                <p class="mb-0"><i class="fa-solid fa-location-dot me-2 text-purple" style="width: 15px;"></i> มหาวิทยาลัยมหาสารคาม</p>
            </div>
        </div>
        <div class="col-4 text-end">
            <?php 
                $hasPic = !empty($user['profile_picture']) && file_exists(__DIR__ . '/../../uploads/avatars/' . $user['profile_picture']);
                if ($hasPic): 
            ?>
                <img src="<?php echo $base_url; ?>/uploads/avatars/<?php echo htmlspecialchars($user['profile_picture']); ?>" class="profile-pic" alt="Profile">
            <?php else: ?>
                <img src="<?php echo $base_url; ?>/assets/img/default-avatar.svg" class="profile-pic border shadow-xs" alt="Profile" style="object-fit: cover;">

            <?php endif; ?>
        </div>
    </div>

    <?php if($edus->rowCount() > 0): ?>
    <div class="section-title">ประวัติการศึกษา (Education)</div>
    <?php while($row = $edus->fetch()): ?>
        <div class="info-row d-flex justify-content-between">
            <span><strong><?php echo htmlspecialchars($row['degree_name']); ?></strong>, <?php echo htmlspecialchars($row['institution']); ?></span>
            <span class="text-muted">พ.ศ. <?php echo htmlspecialchars($row['grad_year']); ?></span>
        </div>
    <?php endwhile; endif; ?>

    <?php if($exps->rowCount() > 0): ?>
    <div class="section-title">ประวัติการทำงาน (Experience)</div>
    <?php while($row = $exps->fetch()): ?>
        <div class="info-row">
            <strong><?php echo htmlspecialchars($row['job_title']); ?></strong> - <?php echo htmlspecialchars($row['organization']); ?> 
            <span class="float-end text-muted"><?php echo htmlspecialchars($row['duration']); ?></span>
        </div>
    <?php endwhile; endif; ?>

    <?php if($grants->rowCount() > 0): ?>
    <div class="section-title">ทุนวิจัยที่ได้รับ (Grants)</div>
    <ul class="bullet-list">
        <?php while($row = $grants->fetch()): ?>
            <li>
                <strong><?php echo htmlspecialchars($row['grant_name']); ?></strong> 
                <?php if($row['grant_source']) echo "- แหล่งทุน: " . htmlspecialchars($row['grant_source']); ?> 
                <span class="text-muted">(<?php echo htmlspecialchars($row['grant_year']); ?>)</span>
            </li>
        <?php endwhile; ?>
    </ul>
    <?php endif; ?>

    <?php if($courses->rowCount() > 0): ?>
    <div class="section-title">รายวิชาที่สอน (Teaching Courses)</div>
    <ul class="bullet-list">
        <?php while($row = $courses->fetch()): ?>
            <li>
                <?php if($row['course_code']) echo "<strong>" . htmlspecialchars($row['course_code']) . "</strong> "; ?>
                <?php echo htmlspecialchars($row['course_name']); ?> 
                <span class="text-muted">(ระดับ: <?php echo htmlspecialchars($row['course_level']); ?>)</span>
            </li>
        <?php endwhile; ?>
    </ul>
    <?php endif; ?>

    <?php if($advisors->rowCount() > 0): ?>
    <div class="section-title">ที่ปรึกษาวิทยานิพนธ์ (Thesis Advisor)</div>
    <ul class="bullet-list">
        <?php while($row = $advisors->fetch()): ?>
            <li>
                <strong><?php echo htmlspecialchars($row['adv_type']); ?>:</strong> <?php echo htmlspecialchars($row['student_name']); ?>. 
                <i>"<?php echo htmlspecialchars($row['thesis_title']); ?>"</i> 
                <span class="text-muted">(<?php echo htmlspecialchars($row['adv_year']); ?>)</span>
            </li>
        <?php endwhile; ?>
    </ul>
    <?php endif; ?>

    <?php if($committees->rowCount() > 0): ?>
    <div class="section-title">กรรมการสอบวิทยานิพนธ์ (Thesis Committee)</div>
    <ul class="bullet-list">
        <?php while($row = $committees->fetch()): ?>
            <li>
                <?php echo htmlspecialchars($row['student_name']); ?>. 
                <i>"<?php echo htmlspecialchars($row['thesis_title']); ?>"</i> 
                <span class="text-muted">(<?php echo htmlspecialchars($row['cmt_year']); ?>)</span>
            </li>
        <?php endwhile; ?>
    </ul>
    <?php endif; ?>

    <?php if($awards->rowCount() > 0): ?>
    <div class="section-title">รางวัลเกียรติยศ (Awards & Honors)</div>
    <ul class="bullet-list">
        <?php while($row = $awards->fetch()): ?>
            <li>
                <strong><?php echo htmlspecialchars($row['award_name']); ?></strong> 
                <?php if($row['award_org']) echo "- มอบโดย: " . htmlspecialchars($row['award_org']); ?> 
                <span class="text-muted">(<?php echo htmlspecialchars($row['award_year']); ?>)</span>
            </li>
        <?php endwhile; ?>
    </ul>
    <?php endif; ?>

    <div class="row mt-4">
        <?php if($certs->rowCount() > 0): ?>
        <div class="col-6">
            <div class="section-title">ใบรับรอง (Certificates)</div>
            <?php while($row = $certs->fetch()): ?>
                <div class="small mb-3 border-bottom pb-2">
                    <div class="fw-bold text-dark"><i class="fa-solid fa-award me-1 text-gold"></i> <?php echo htmlspecialchars($row['cert_name']); ?></div>
                    <div class="text-muted extra-small mt-1"><?php echo htmlspecialchars($row['issuing_org']); ?> (<?php echo htmlspecialchars($row['issue_date']); ?>)</div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <?php if($langs->rowCount() > 0): ?>
        <div class="col-6">
            <div class="section-title">ทักษะภาษา (Languages)</div>
            <?php while($row = $langs->fetch()): ?>
                <div class="info-row d-flex justify-content-between px-2">
                    <span><strong><?php echo htmlspecialchars($row['language_name']); ?></strong></span>
                    <span class="text-purple fw-bold"><?php echo htmlspecialchars($row['proficiency']); ?></span>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if($skills->rowCount() > 0): ?>
    <div class="section-title">ความเชี่ยวชาญ (Expertise)</div>
    <div class="d-flex flex-wrap gap-2 pt-1 pb-4">
        <?php while($row = $skills->fetch()): ?>
            <span class="badge border text-dark fw-normal px-3 py-2 bg-light">
                <?php echo htmlspecialchars($row['skill_name']); ?> 
                <small class="text-muted ms-1">(Lvl: <?php echo htmlspecialchars($row['skill_level']); ?>)</small>
            </span>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <?php if($research->rowCount() > 0): ?>
    <div class="section-title">งานวิจัยและผลงานวิชาการ (Research & Publications)</div>
    <div class="pt-1">
        <?php while($row = $research->fetch()): 
            $year = !empty($row['publish_year']) ? "({$row['publish_year']})." : "(n.d.).";
            $title = !empty($row['title']) ? htmlspecialchars($row['title']) . "." : "";
            $publisher = !empty($row['publisher']) ? "<i>" . htmlspecialchars($row['publisher']) . "</i>." : "";
            
            // เช็คและแสดงรายชื่อผู้แต่ง
            $authors = "";
            if (!empty($row['authors'])) {
                $authors = htmlspecialchars($row['authors']);
            } else {
                // กรณีไม่มีในช่อง authors ให้ดึงชื่อเจ้าของ CV มาใส่ (อังกฤษก่อน ถ้าไม่มีค่อยไทย)
                if (!empty($user['last_name_en']) && !empty($user['first_name_en'])) {
                    $authors = htmlspecialchars($user['last_name_en']) . ", " . substr(htmlspecialchars($user['first_name_en']), 0, 1) . ".";
                } else {
                    $authors = htmlspecialchars($user['last_name']) . ", " . substr(htmlspecialchars($user['first_name']), 0, 3) . ".";
                }
            }

            // ซ่อนลิงก์ถ้ามาจาก scholar.google.com
            $link = "";
            if (!empty($row['url_link']) && strpos($row['url_link'], 'scholar.google.com') === false) {
                $link = "<a href='" . htmlspecialchars($row['url_link']) . "' target='_blank'>" . htmlspecialchars($row['url_link']) . "</a>";
            }
        ?>
            <div class="apa-citation">
                <?php echo $authors; ?> <?php echo $year; ?> 
                <?php echo $title; ?> <?php echo $publisher; ?> <?php echo $link; ?>
            </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

</div>

</body>
</html>