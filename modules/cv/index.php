<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// ---------------------------------------------------------
// Logic จัดการข้อมูล (เพิ่ม / แก้ไข / ลบ / Import)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $upload_dir = '../../uploads/cv_certs/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    try {
        if ($action == 'add_edu') {
            $stmt = $pdo->prepare("INSERT INTO cv_education (user_id, degree_name, institution, grad_year) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['degree_name'], $_POST['institution'], $_POST['grad_year']]);
            $success_msg = "เพิ่มประวัติการศึกษาเรียบร้อย";
        } elseif ($action == 'edit_edu') {
            $stmt = $pdo->prepare("UPDATE cv_education SET degree_name=?, institution=?, grad_year=? WHERE edu_id=? AND user_id=?");
            $stmt->execute([$_POST['degree_name'], $_POST['institution'], $_POST['grad_year'], $_POST['edu_id'], $user_id]);
            $success_msg = "แก้ไขประวัติการศึกษาเรียบร้อย";
        }
        elseif ($action == 'add_exp') {
            $stmt = $pdo->prepare("INSERT INTO cv_experience (user_id, job_title, organization, duration) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['job_title'], $_POST['organization'], $_POST['duration']]);
            $success_msg = "เพิ่มประสบการณ์ทำงานเรียบร้อย";
        } elseif ($action == 'edit_exp') {
            $stmt = $pdo->prepare("UPDATE cv_experience SET job_title=?, organization=?, duration=? WHERE exp_id=? AND user_id=?");
            $stmt->execute([$_POST['job_title'], $_POST['organization'], $_POST['duration'], $_POST['exp_id'], $user_id]);
            $success_msg = "แก้ไขประสบการณ์ทำงานเรียบร้อย";
        }
        elseif ($action == 'add_skill') {
            $stmt = $pdo->prepare("INSERT INTO cv_skills (user_id, skill_name, skill_level) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $_POST['skill_name'], $_POST['skill_level']]);
            $success_msg = "เพิ่มทักษะเรียบร้อย";
        } elseif ($action == 'edit_skill') {
            $stmt = $pdo->prepare("UPDATE cv_skills SET skill_name=?, skill_level=? WHERE skill_id=? AND user_id=?");
            $stmt->execute([$_POST['skill_name'], $_POST['skill_level'], $_POST['skill_id'], $user_id]);
            $success_msg = "แก้ไขทักษะเรียบร้อย";
        }
        elseif ($action == 'add_cert') {
            $cert_file = null;
            if (!empty($_FILES['cert_file']['name'])) {
                $ext = pathinfo($_FILES['cert_file']['name'], PATHINFO_EXTENSION);
                $cert_file = "cert_" . $user_id . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES['cert_file']['tmp_name'], $upload_dir . $cert_file);
            }
            $stmt = $pdo->prepare("INSERT INTO cv_certificates (user_id, cert_name, issuing_org, issue_date, cert_file) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['cert_name'], $_POST['issuing_org'], $_POST['issue_date'], $cert_file]);
            $success_msg = "เพิ่มใบรับรองเรียบร้อย";
        } elseif ($action == 'edit_cert') {
            $cert_id = $_POST['cert_id'];
            $sql = "UPDATE cv_certificates SET cert_name=?, issuing_org=?, issue_date=? WHERE cert_id=? AND user_id=?";
            $params = [$_POST['cert_name'], $_POST['issuing_org'], $_POST['issue_date'], $cert_id, $user_id];
            if (!empty($_FILES['cert_file']['name'])) {
                $ext = pathinfo($_FILES['cert_file']['name'], PATHINFO_EXTENSION);
                $new_file = "cert_" . $user_id . "_" . time() . "." . $ext;
                if (move_uploaded_file($_FILES['cert_file']['tmp_name'], $upload_dir . $new_file)) {
                    $sql = "UPDATE cv_certificates SET cert_name=?, issuing_org=?, issue_date=?, cert_file=? WHERE cert_id=? AND user_id=?";
                    $params = [$_POST['cert_name'], $_POST['issuing_org'], $_POST['issue_date'], $new_file, $cert_id, $user_id];
                }
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $success_msg = "แก้ไขใบรับรองเรียบร้อย";
        }
        elseif ($action == 'add_lang') {
            $stmt = $pdo->prepare("INSERT INTO cv_languages (user_id, language_name, proficiency) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $_POST['language_name'], $_POST['proficiency']]);
            $success_msg = "เพิ่มทักษะภาษาเรียบร้อย";
        } elseif ($action == 'edit_lang') {
            $stmt = $pdo->prepare("UPDATE cv_languages SET language_name=?, proficiency=? WHERE lang_id=? AND user_id=?");
            $stmt->execute([$_POST['language_name'], $_POST['proficiency'], $_POST['lang_id'], $user_id]);
            $success_msg = "แก้ไขทักษะภาษาเรียบร้อย";
        }
        elseif ($action == 'add_res') {
            $stmt = $pdo->prepare("INSERT INTO cv_research (user_id, title, authors, publisher, publish_year, url_link) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['title'], $_POST['authors'] ?? '', $_POST['publisher'], $_POST['publish_year'], $_POST['url_link']]);
            $success_msg = "เพิ่มงานวิจัยเรียบร้อย";
        } elseif ($action == 'edit_res') {
            $stmt = $pdo->prepare("UPDATE cv_research SET title=?, authors=?, publisher=?, publish_year=?, url_link=? WHERE res_id=? AND user_id=?");
            $stmt->execute([$_POST['title'], $_POST['authors'] ?? '', $_POST['publisher'], $_POST['publish_year'], $_POST['url_link'], $_POST['res_id'], $user_id]);
            $success_msg = "แก้ไขงานวิจัยเรียบร้อย";
        }
        elseif ($action == 'add_grant') {
            $stmt = $pdo->prepare("INSERT INTO cv_grants (user_id, grant_name, grant_source, grant_year) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['grant_name'], $_POST['grant_source'], $_POST['grant_year']]);
            $success_msg = "เพิ่มทุนวิจัยเรียบร้อย";
        } elseif ($action == 'edit_grant') {
            $stmt = $pdo->prepare("UPDATE cv_grants SET grant_name=?, grant_source=?, grant_year=? WHERE grant_id=? AND user_id=?");
            $stmt->execute([$_POST['grant_name'], $_POST['grant_source'], $_POST['grant_year'], $_POST['grant_id'], $user_id]);
            $success_msg = "แก้ไขทุนวิจัยเรียบร้อย";
        }
        elseif ($action == 'add_award') {
            $stmt = $pdo->prepare("INSERT INTO cv_awards (user_id, award_name, award_org, award_year) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['award_name'], $_POST['award_org'], $_POST['award_year']]);
            $success_msg = "เพิ่มรางวัลเรียบร้อย";
        } elseif ($action == 'edit_award') {
            $stmt = $pdo->prepare("UPDATE cv_awards SET award_name=?, award_org=?, award_year=? WHERE award_id=? AND user_id=?");
            $stmt->execute([$_POST['award_name'], $_POST['award_org'], $_POST['award_year'], $_POST['award_id'], $user_id]);
            $success_msg = "แก้ไขรางวัลเรียบร้อย";
        }
        elseif ($action == 'add_adv') {
            $stmt = $pdo->prepare("INSERT INTO cv_advisor (user_id, student_name, thesis_title, adv_year, adv_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['student_name'], $_POST['thesis_title'], $_POST['adv_year'], $_POST['adv_type']]);
            $success_msg = "เพิ่มที่ปรึกษาวิทยานิพนธ์เรียบร้อย";
        } elseif ($action == 'edit_adv') {
            $stmt = $pdo->prepare("UPDATE cv_advisor SET student_name=?, thesis_title=?, adv_year=?, adv_type=? WHERE adv_id=? AND user_id=?");
            $stmt->execute([$_POST['student_name'], $_POST['thesis_title'], $_POST['adv_year'], $_POST['adv_type'], $_POST['adv_id'], $user_id]);
            $success_msg = "แก้ไขที่ปรึกษาวิทยานิพนธ์เรียบร้อย";
        }
        elseif ($action == 'add_cmt') {
            $stmt = $pdo->prepare("INSERT INTO cv_committee (user_id, student_name, thesis_title, cmt_year) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['student_name'], $_POST['thesis_title'], $_POST['cmt_year']]);
            $success_msg = "เพิ่มกรรมการสอบวิทยานิพนธ์เรียบร้อย";
        } elseif ($action == 'edit_cmt') {
            $stmt = $pdo->prepare("UPDATE cv_committee SET student_name=?, thesis_title=?, cmt_year=? WHERE cmt_id=? AND user_id=?");
            $stmt->execute([$_POST['student_name'], $_POST['thesis_title'], $_POST['cmt_year'], $_POST['cmt_id'], $user_id]);
            $success_msg = "แก้ไขกรรมการสอบวิทยานิพนธ์เรียบร้อย";
        }
        elseif ($action == 'add_course') {
            $stmt = $pdo->prepare("INSERT INTO cv_courses (user_id, course_code, course_name, course_level) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $_POST['course_code'], $_POST['course_name'], $_POST['course_level']]);
            $success_msg = "เพิ่มรายวิชาที่สอนเรียบร้อย";
        } elseif ($action == 'edit_course') {
            $stmt = $pdo->prepare("UPDATE cv_courses SET course_code=?, course_name=?, course_level=? WHERE course_id=? AND user_id=?");
            $stmt->execute([$_POST['course_code'], $_POST['course_name'], $_POST['course_level'], $_POST['course_id'], $user_id]);
            $success_msg = "แก้ไขรายวิชาที่สอนเรียบร้อย";
        }
        // =====================================================
        // [อัปเดตใหม่] ระบบนำเข้าข้อมูลผ่านไฟล์ CSV
        // =====================================================
        elseif ($action == 'import_csv' && isset($_FILES['csv_file'])) {
            $import_type = $_POST['import_type'];
            $file = $_FILES['csv_file']['tmp_name'];
            $import_count = 0;

            if ($_FILES['csv_file']['size'] > 0) {
                $handle = fopen($file, "r");
                // ข้ามบรรทัดแรก (Header)
                fgetcsv($handle, 10000, ",");

                $pdo->beginTransaction(); 

                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    // 1. ป้องกัน Error 500 จากค่าว่าง และจัดการเรื่องภาษาต่างดาว
                    $data = array_map(function($val) {
                        if ($val === null || trim($val) === '') return ''; 
                        
                        if (mb_detect_encoding($val, 'UTF-8', true) === 'UTF-8') {
                            return $val;
                        }
                        return @iconv('Windows-874', 'UTF-8//IGNORE', $val);
                    }, $data);

                    // 2. ป้องกัน Error 500 (Undefined array key) กรณีคอลัมน์มีไม่ครบ
                    $col0 = trim($data[0] ?? '');
                    $col1 = trim($data[1] ?? '');
                    $col2 = trim($data[2] ?? '');
                    $col3 = trim($data[3] ?? '');

                    // แยกการ Insert ตามประเภทที่ผู้ใช้เลือก
                    if ($import_type == 'cv_courses' && !empty($col1)) {
                        $stmt = $pdo->prepare("INSERT INTO cv_courses (user_id, course_code, course_name, course_level) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$user_id, $col0, $col1, $col2 ?: 'ปริญญาตรี']);
                        $import_count++;
                    } 
                    elseif ($import_type == 'cv_advisor' && !empty($col0)) {
                        $stmt = $pdo->prepare("INSERT INTO cv_advisor (user_id, student_name, thesis_title, adv_year, adv_type) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$user_id, $col0, $col1, $col2, $col3 ?: 'ที่ปรึกษาหลัก']);
                        $import_count++;
                    }
                    elseif ($import_type == 'cv_committee' && !empty($col0)) {
                        $stmt = $pdo->prepare("INSERT INTO cv_committee (user_id, student_name, thesis_title, cmt_year) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$user_id, $col0, $col1, $col2]);
                        $import_count++;
                    }
                    elseif ($import_type == 'cv_awards' && !empty($col0)) {
                        $stmt = $pdo->prepare("INSERT INTO cv_awards (user_id, award_name, award_org, award_year) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$user_id, $col0, $col1, $col2]);
                        $import_count++;
                    }
                }
                
                fclose($handle);
                $pdo->commit();
                $success_msg = "นำเข้าข้อมูลสำเร็จจำนวน $import_count รายการ";
            } else {
                throw new Exception("ไฟล์ว่างเปล่า กรุณาอัปโหลดไฟล์ที่มีข้อมูล");
            }
        }
        // =====================================================
        elseif ($action == 'delete_item') {
            $table = $_POST['table_name'];
            $id_col = $_POST['id_column'];
            $id_val = $_POST['id_value'];
            $allowed_tables = ['cv_education', 'cv_experience', 'cv_skills', 'cv_certificates', 'cv_languages', 'cv_research', 'cv_grants', 'cv_awards', 'cv_advisor', 'cv_committee', 'cv_courses'];
            
            if (in_array($table, $allowed_tables)) {
                $stmt = $pdo->prepare("DELETE FROM $table WHERE $id_col = ? AND user_id = ?");
                $stmt->execute([$id_val, $user_id]);
                $success_msg = "ลบข้อมูลเรียบร้อย";
            }
        }
    } catch (Exception $e) { 
        if($pdo->inTransaction()) $pdo->rollBack();
        $error_msg = "Error: " . $e->getMessage(); 
    }
}

if (isset($_GET['status']) && $_GET['status'] == 'success') {
    $count = $_GET['count'] ?? 0;
    $success_msg = "นำเข้าผลงานวิชาการจำนวน $count รายการ เรียบร้อยแล้ว";
}

$user_info = $pdo->prepare("SELECT scholar_id, last_scholar_sync FROM users WHERE user_id = ?");
$user_info->execute([$user_id]);
$user_data = $user_info->fetch();

$edus = $pdo->prepare("SELECT * FROM cv_education WHERE user_id = ? ORDER BY grad_year DESC"); $edus->execute([$user_id]);
$exps = $pdo->prepare("SELECT * FROM cv_experience WHERE user_id = ? ORDER BY duration DESC"); $exps->execute([$user_id]);
$skills = $pdo->prepare("SELECT * FROM cv_skills WHERE user_id = ? ORDER BY skill_level DESC"); $skills->execute([$user_id]);
$certs = $pdo->prepare("SELECT * FROM cv_certificates WHERE user_id = ? ORDER BY issue_date DESC"); $certs->execute([$user_id]);
$langs = $pdo->prepare("SELECT * FROM cv_languages WHERE user_id = ?"); $langs->execute([$user_id]);
$research = $pdo->prepare("SELECT * FROM cv_research WHERE user_id = ? ORDER BY publish_year DESC"); $research->execute([$user_id]);
$grants = $pdo->prepare("SELECT * FROM cv_grants WHERE user_id = ? ORDER BY grant_year DESC"); $grants->execute([$user_id]);
$awards = $pdo->prepare("SELECT * FROM cv_awards WHERE user_id = ? ORDER BY award_year DESC"); $awards->execute([$user_id]);
$advisors = $pdo->prepare("SELECT * FROM cv_advisor WHERE user_id = ? ORDER BY adv_year DESC"); $advisors->execute([$user_id]);
$committees = $pdo->prepare("SELECT * FROM cv_committee WHERE user_id = ? ORDER BY cmt_year DESC"); $committees->execute([$user_id]);
$courses = $pdo->prepare("SELECT * FROM cv_courses WHERE user_id = ? ORDER BY course_name ASC"); $courses->execute([$user_id]);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-id-card-clip me-2"></i>จัดการข้อมูลประวัติ (CV)</h1>
            <p class="text-muted small">บันทึกข้อมูลเพื่อนำไปแสดงผลในหน้า Public CV สำหรับบุคลากร</p>
        </div>
        <div class="d-flex gap-2">
            <a href="public_cv.php?u=<?php echo $user_id; ?>" target="_blank" class="btn btn-outline-purple rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-globe me-1"></i> ดูหน้า CV (Public)
            </a>
            <button class="btn btn-gold rounded-pill px-4 shadow-sm fw-bold" onclick="window.open('public_cv.php?u=<?php echo $user_id; ?>&print=true', '_blank')">
                <i class="fa-solid fa-print me-1"></i> พิมพ์ CV (A4)
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 bg-white d-print-none overflow-hidden" style="border-radius: 1rem;">
        <div class="card-body p-4 position-relative">
            <i class="fa-brands fa-google scholar-bg-icon"></i>
            <div class="row align-items-center position-relative">
                <div class="col-md-7">
                    <h5 class="fw-bold text-purple mb-1"><i class="fa-solid fa-sync-alt me-2"></i>Google Scholar Sync</h5>
                    <p class="text-muted small mb-0">ดึงข้อมูลผลงานวิชาการอัตโนมัติจาก Google Scholar Profile</p>
                </div>
                <div class="col-md-5 text-md-end mt-3 mt-md-0">
                    <div class="input-group shadow-sm">
                        <input type="text" id="scholar_id_input" class="form-control border-purple" placeholder="ระบุ Scholar ID (เช่น Yz...)" value="<?php echo htmlspecialchars($user_data['scholar_id'] ?? ''); ?>">
                        <button class="btn btn-purple px-4" onclick="fetchScholarData(this)"><i class="fa-solid fa-rotate me-1"></i> Sync ข้อมูล</button>
                    </div>
                    <small class="text-muted d-block mt-2">อัปเดตล่าสุด: <?php echo !empty($user_data['last_scholar_sync']) ? date('d/m/Y H:i', strtotime($user_data['last_scholar_sync'])) : 'ยังไม่เคยซิงก์ข้อมูล'; ?></small>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success shadow-sm border-0 border-start border-success border-4 alert-dismissible fade show">
            <i class="fa-solid fa-check-circle me-2"></i> <?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger shadow-sm border-0 border-start border-danger border-4 alert-dismissible fade show">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo $error_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-graduation-cap me-2"></i>ประวัติการศึกษา</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalEdu"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($edus->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div><div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['degree_name']); ?></div><div class="extra-small text-muted"><?php echo htmlspecialchars($row['institution']); ?> (<?php echo $row['grad_year']; ?>)</div></div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditEdu('<?php echo $row['edu_id']; ?>','<?php echo htmlspecialchars(addslashes($row['degree_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['institution'])); ?>','<?php echo $row['grad_year']; ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_education','edu_id','<?php echo $row['edu_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-briefcase me-2"></i>ประวัติการทำงาน</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalExp"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($exps->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div><div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['job_title']); ?></div><div class="extra-small text-muted"><?php echo htmlspecialchars($row['organization']); ?></div><span class="badge bg-gold-subtle text-gold extra-small mt-1"><?php echo htmlspecialchars($row['duration']); ?></span></div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditExp('<?php echo $row['exp_id']; ?>','<?php echo htmlspecialchars(addslashes($row['job_title'])); ?>','<?php echo htmlspecialchars(addslashes($row['organization'])); ?>','<?php echo htmlspecialchars(addslashes($row['duration'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_experience','exp_id','<?php echo $row['exp_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-bolt me-2 text-warning"></i>ทักษะและความเชี่ยวชาญ</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalSkill"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($skills->fetchAll() as $row): ?>
                        <div class="mb-3 px-2">
                            <div class="d-flex justify-content-between mb-1"><span class="small fw-bold text-dark"><?php echo htmlspecialchars($row['skill_name']); ?></span>
                                <div class="d-print-none">
                                    <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditSkill('<?php echo $row['skill_id']; ?>','<?php echo htmlspecialchars(addslashes($row['skill_name'])); ?>','<?php echo $row['skill_level']; ?>')"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_skills','skill_id','<?php echo $row['skill_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                            <div class="progress shadow-xs" style="height: 6px;"><div class="progress-bar bg-purple" style="width: <?php echo ($row['skill_level']*20); ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-certificate me-2"></i>ใบรับรอง / Certificates</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalCert"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($certs->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['cert_name']); ?></div>
                                <div class="extra-small text-muted"><?php echo htmlspecialchars($row['issuing_org']); ?> (<?php echo $row['issue_date']; ?>)</div>
                                <?php if($row['cert_file']): ?><a href="../../uploads/cv_certs/<?php echo $row['cert_file']; ?>" target="_blank" class="badge bg-success-subtle text-success text-decoration-none mt-1"><i class="fa-solid fa-file-pdf me-1"></i> ดูไฟล์</a><?php endif; ?>
                            </div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditCert('<?php echo $row['cert_id']; ?>','<?php echo htmlspecialchars(addslashes($row['cert_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['issuing_org'])); ?>','<?php echo htmlspecialchars(addslashes($row['issue_date'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_certificates','cert_id','<?php echo $row['cert_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-chalkboard-user me-2 text-info"></i>รายวิชาที่สอน</h6>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill me-1 d-print-none" data-bs-toggle="modal" data-bs-target="#modalImportCSV" onclick="setImportType('cv_courses')"><i class="fa-solid fa-file-import me-1"></i> Import</button>
                        <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalCourse"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($courses->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['course_name']); ?></div>
                                <div class="extra-small text-muted"><?php echo htmlspecialchars($row['course_code'] ?? ''); ?> | <?php echo htmlspecialchars($row['course_level']); ?></div>
                            </div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditCourse('<?php echo $row['course_id']; ?>','<?php echo htmlspecialchars(addslashes($row['course_code'])); ?>','<?php echo htmlspecialchars(addslashes($row['course_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['course_level'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_courses','course_id','<?php echo $row['course_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-user-graduate me-2 text-success"></i>ที่ปรึกษาวิทยานิพนธ์</h6>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill me-1 d-print-none" data-bs-toggle="modal" data-bs-target="#modalImportCSV" onclick="setImportType('cv_advisor')"><i class="fa-solid fa-file-import me-1"></i> Import</button>
                        <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalAdv"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($advisors->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                <div class="extra-small text-muted fst-italic">"<?php echo htmlspecialchars($row['thesis_title']); ?>"</div>
                                <span class="badge bg-light text-dark border extra-small mt-1"><?php echo htmlspecialchars($row['adv_type']); ?> (<?php echo $row['adv_year']; ?>)</span>
                            </div>
                            <div class="d-flex d-print-none action-btns align-items-start">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditAdv('<?php echo $row['adv_id']; ?>','<?php echo htmlspecialchars(addslashes($row['student_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['thesis_title'])); ?>','<?php echo htmlspecialchars(addslashes($row['adv_year'])); ?>','<?php echo htmlspecialchars(addslashes($row['adv_type'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_advisor','adv_id','<?php echo $row['adv_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-gavel me-2 text-danger"></i>กรรมการสอบวิทยานิพนธ์</h6>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill me-1 d-print-none" data-bs-toggle="modal" data-bs-target="#modalImportCSV" onclick="setImportType('cv_committee')"><i class="fa-solid fa-file-import me-1"></i> Import</button>
                        <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalCmt"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($committees->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                <div class="extra-small text-muted fst-italic">"<?php echo htmlspecialchars($row['thesis_title']); ?>"</div>
                                <div class="extra-small text-muted mt-1">ปี พ.ศ. <?php echo $row['cmt_year']; ?></div>
                            </div>
                            <div class="d-flex d-print-none action-btns align-items-start">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditCmt('<?php echo $row['cmt_id']; ?>','<?php echo htmlspecialchars(addslashes($row['student_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['thesis_title'])); ?>','<?php echo htmlspecialchars(addslashes($row['cmt_year'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_committee','cmt_id','<?php echo $row['cmt_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-trophy me-2 text-warning"></i>รางวัลเกียรติยศ</h6>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill me-1 d-print-none" data-bs-toggle="modal" data-bs-target="#modalImportCSV" onclick="setImportType('cv_awards')"><i class="fa-solid fa-file-import me-1"></i> Import</button>
                        <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalAward"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($awards->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['award_name']); ?></div>
                                <div class="extra-small text-muted"><?php echo htmlspecialchars($row['award_org']); ?></div>
                                <span class="badge bg-gold-subtle text-gold extra-small mt-1"><?php echo htmlspecialchars($row['award_year']); ?></span>
                            </div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditAward('<?php echo $row['award_id']; ?>','<?php echo htmlspecialchars(addslashes($row['award_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['award_org'])); ?>','<?php echo htmlspecialchars(addslashes($row['award_year'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_awards','award_id','<?php echo $row['award_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-sack-dollar me-2 text-success"></i>ทุนวิจัยที่ได้รับ</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalGrant"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <?php foreach($grants->fetchAll() as $row): ?>
                        <div class="cv-item d-flex justify-content-between p-2 rounded mb-2 border-bottom-dashed">
                            <div>
                                <div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['grant_name']); ?></div>
                                <div class="extra-small text-muted">แหล่งทุน: <?php echo htmlspecialchars($row['grant_source']); ?></div>
                                <span class="badge bg-light text-dark border extra-small mt-1">ปี พ.ศ. <?php echo htmlspecialchars($row['grant_year']); ?></span>
                            </div>
                            <div class="d-flex d-print-none action-btns">
                                <button class="btn btn-link btn-sm text-primary p-0 me-2" onclick="openEditGrant('<?php echo $row['grant_id']; ?>','<?php echo htmlspecialchars(addslashes($row['grant_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['grant_source'])); ?>','<?php echo htmlspecialchars(addslashes($row['grant_year'])); ?>')"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_grants','grant_id','<?php echo $row['grant_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-12 mb-2 mt-4">
            <div class="card border-0 shadow-sm rounded-4 bg-white">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-language me-2"></i>ทักษะภาษา</h6>
                    <button class="btn btn-sm btn-purple rounded-circle d-print-none" data-bs-toggle="modal" data-bs-target="#modalLang"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="card-body pt-0">
                    <div class="row g-2">
                        <?php foreach($langs->fetchAll() as $row): ?>
                            <div class="col-md-3 col-6">
                                <div class="p-2 border rounded bg-light d-flex justify-content-between align-items-center shadow-xs">
                                    <div><div class="fw-bold small text-dark"><?php echo htmlspecialchars($row['language_name']); ?></div><div class="extra-small text-purple fw-bold"><?php echo htmlspecialchars($row['proficiency']); ?></div></div>
                                    <div class="d-print-none action-btns">
                                        <button class="btn btn-link btn-sm text-primary p-0 me-1" onclick="openEditLang('<?php echo $row['lang_id']; ?>','<?php echo htmlspecialchars(addslashes($row['language_name'])); ?>','<?php echo htmlspecialchars(addslashes($row['proficiency'])); ?>')"><i class="fa-solid fa-pen"></i></button>
                                        <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteItem('cv_languages','lang_id','<?php echo $row['lang_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-white" id="research_section">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h6 class="mb-0 fw-bold text-purple"><i class="fa-solid fa-book-open me-2"></i>งานวิจัย / ผลงานทางวิชาการ</h6>
                    <button class="btn btn-sm btn-purple rounded-pill px-3 d-print-none" data-bs-toggle="modal" data-bs-target="#modalRes"><i class="fa-solid fa-plus me-1"></i> เพิ่มผลงาน</button>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light small">
                                <tr>
                                    <th>หัวข้องานวิจัย / แหล่งตีพิมพ์</th>
                                    <th class="text-center" width="10%">ปี พ.ศ.</th>
                                    <th class="text-end" width="15%">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($research->fetchAll() as $row): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($row['authors'])): ?>
                                                <div class="text-secondary small fw-bold mb-1"><?php echo htmlspecialchars($row['authors']); ?></div>
                                            <?php endif; ?>
                                            <div class="fw-bold text-dark small"><?php echo htmlspecialchars($row['title']); ?></div>
                                            <div class="extra-small text-muted"><i class="fa-solid fa-print me-1"></i> Publisher: <?php echo htmlspecialchars($row['publisher']); ?></div>
                                            <?php if($row['url_link']): ?><a href="<?php echo htmlspecialchars($row['url_link']); ?>" target="_blank" class="extra-small text-primary"><i class="fa-solid fa-external-link-alt me-1"></i> ดูลิงก์ผลงาน / DOI</a><?php endif; ?>
                                        </td>
                                        <td class="text-center small"><?php echo $row['publish_year']; ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-circle me-1" onclick="openEditRes('<?php echo $row['res_id']; ?>','<?php echo htmlspecialchars(addslashes($row['title'])); ?>','<?php echo htmlspecialchars(addslashes($row['authors'] ?? '')); ?>','<?php echo htmlspecialchars(addslashes($row['publisher'])); ?>','<?php echo $row['publish_year']; ?>','<?php echo htmlspecialchars($row['url_link']); ?>')"><i class="fa-solid fa-pen"></i></button>
                                            <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="deleteItem('cv_research','res_id','<?php echo $row['res_id']; ?>')"><i class="fa-solid fa-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="modalEdu" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มการศึกษา</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_edu"><div class="mb-3"><label class="form-label small fw-bold">วุฒิการศึกษา</label><input type="text" name="degree_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">สถาบัน</label><input type="text" name="institution" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ปีที่จบ (พ.ศ.)</label><input type="text" name="grad_year" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalExp" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มประสบการณ์ทำงาน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_exp"><div class="mb-3"><label class="form-label small fw-bold">ตำแหน่ง</label><input type="text" name="job_title" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">หน่วยงาน</label><input type="text" name="organization" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระยะเวลา</label><input type="text" name="duration" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalSkill" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มทักษะ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_skill"><div class="mb-3"><label class="form-label small fw-bold">ชื่อทักษะ</label><input type="text" name="skill_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระดับ (1-5)</label><select name="skill_level" class="form-select"><option value="1">1-พื้นฐาน</option><option value="2">2-พอใช้</option><option value="3">3-ปานกลาง</option><option value="4">4-ดี</option><option value="5">5-เชี่ยวชาญ</option></select></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalCert" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" enctype="multipart/form-data" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มใบรับรอง</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_cert"><div class="mb-3"><label class="form-label small fw-bold">ชื่อใบรับรอง</label><input type="text" name="cert_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ผู้ออกให้</label><input type="text" name="issuing_org" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">วันที่ได้รับ</label><input type="text" name="issue_date" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">แนบไฟล์</label><input type="file" name="cert_file" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalLang" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มภาษา</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_lang"><div class="mb-3"><label class="form-label small fw-bold">ภาษา</label><input type="text" name="language_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระดับ</label><select name="proficiency" class="form-select"><option value="ดีมาก">ดีมาก</option><option value="ดี">ดี</option><option value="พอใช้">พอใช้</option><option value="พื้นฐาน">พื้นฐาน</option></select></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>

<div class="modal fade" id="modalRes" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มผลงาน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_res"><div class="mb-3"><label class="form-label small fw-bold">ชื่อผลงาน</label><input type="text" name="title" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ผู้แต่ง (Authors)</label><input type="text" name="authors" class="form-control" placeholder="เช่น Doe, J., & Smith, A."></div><div class="mb-3"><label class="form-label small fw-bold">แหล่งเผยแพร่</label><input type="text" name="publisher" class="form-control"></div><div class="row"><div class="col-6"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="publish_year" class="form-control"></div><div class="col-6"><label class="form-label small fw-bold">Link (URL/DOI)</label><input type="text" name="url_link" class="form-control"></div></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>

<div class="modal fade" id="modalCourse" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มรายวิชาที่สอน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_course"><div class="mb-3"><label class="form-label small fw-bold">รหัสวิชา</label><input type="text" name="course_code" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อรายวิชา</label><input type="text" name="course_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระดับการศึกษา</label><select name="course_level" class="form-select"><option value="ปริญญาตรี">ปริญญาตรี</option><option value="ปริญญาโท">ปริญญาโท</option><option value="ปริญญาเอก">ปริญญาเอก</option></select></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalAdv" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มที่ปรึกษาวิทยานิพนธ์</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_adv"><div class="mb-3"><label class="form-label small fw-bold">ชื่อนิสิต</label><input type="text" name="student_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อเรื่องวิทยานิพนธ์</label><textarea name="thesis_title" class="form-control" rows="2" required></textarea></div><div class="row"><div class="col-6"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="adv_year" class="form-control"></div><div class="col-6"><label class="form-label small fw-bold">ประเภท</label><select name="adv_type" class="form-select"><option value="ที่ปรึกษาหลัก">ที่ปรึกษาหลัก</option><option value="ที่ปรึกษาร่วม">ที่ปรึกษาร่วม</option></select></div></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalCmt" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มกรรมการสอบวิทยานิพนธ์</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_cmt"><div class="mb-3"><label class="form-label small fw-bold">ชื่อนิสิต</label><input type="text" name="student_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อเรื่องวิทยานิพนธ์</label><textarea name="thesis_title" class="form-control" rows="2" required></textarea></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="cmt_year" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalAward" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มรางวัล</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_award"><div class="mb-3"><label class="form-label small fw-bold">ชื่อรางวัล</label><input type="text" name="award_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">หน่วยงานที่มอบ</label><input type="text" name="award_org" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ. ที่ได้รับ</label><input type="text" name="award_year" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>
<div class="modal fade" id="modalGrant" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-purple text-white"><h5 class="modal-title fw-bold">เพิ่มทุนวิจัย</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" name="action" value="add_grant"><div class="mb-3"><label class="form-label small fw-bold">ชื่อโครงการวิจัย</label><input type="text" name="grant_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">แหล่งทุน</label><input type="text" name="grant_source" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ. ที่ได้รับ</label><input type="text" name="grant_year" class="form-control"></div></div><div class="modal-footer border-0"><button type="submit" class="btn btn-purple px-4 rounded-pill">บันทึก</button></div></form></div></div>

<div class="modal fade" id="modalEditEdu" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขการศึกษา</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_edu"><input type="hidden" name="edu_id" id="edit_edu_id"><div class="mb-3"><label class="form-label small fw-bold">วุฒิการศึกษา</label><input type="text" name="degree_name" id="edit_edu_degree" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">สถาบัน</label><input type="text" name="institution" id="edit_edu_inst" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ปีที่จบ</label><input type="text" name="grad_year" id="edit_edu_year" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditExp" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขประวัติการทำงาน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_exp"><input type="hidden" name="exp_id" id="edit_exp_id"><div class="mb-3"><label class="form-label small fw-bold">ตำแหน่ง</label><input type="text" name="job_title" id="edit_exp_title" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">หน่วยงาน</label><input type="text" name="organization" id="edit_exp_org" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระยะเวลา</label><input type="text" name="duration" id="edit_exp_dur" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditSkill" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขทักษะ</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_skill"><input type="hidden" name="skill_id" id="edit_skill_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อทักษะ</label><input type="text" name="skill_name" id="edit_skill_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระดับ</label><select name="skill_level" id="edit_skill_level" class="form-select"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditCert" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" enctype="multipart/form-data" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขใบรับรอง</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_cert"><input type="hidden" name="cert_id" id="edit_cert_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อใบรับรอง</label><input type="text" name="cert_name" id="edit_cert_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ผู้ออกให้</label><input type="text" name="issuing_org" id="edit_cert_org" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">วันที่ได้รับ</label><input type="text" name="issue_date" id="edit_cert_date" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">เปลี่ยนไฟล์</label><input type="file" name="cert_file" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditLang" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขภาษา</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_lang"><input type="hidden" name="lang_id" id="edit_lang_id"><div class="mb-3"><label class="form-label small fw-bold">ภาษา</label><input type="text" name="language_name" id="edit_lang_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ความชำนาญ</label><select name="proficiency" id="edit_lang_prof" class="form-select"><option value="ดีมาก">ดีมาก</option><option value="ดี">ดี</option><option value="พอใช้">พอใช้</option><option value="พื้นฐาน">พื้นฐาน</option></select></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>

<div class="modal fade" id="modalEditRes" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขผลงาน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_res"><input type="hidden" name="res_id" id="edit_res_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อผลงาน</label><input type="text" name="title" id="edit_res_title" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ผู้แต่ง (Authors)</label><input type="text" name="authors" id="edit_res_authors" class="form-control" placeholder="เช่น Doe, J., & Smith, A."></div><div class="mb-3"><label class="form-label small fw-bold">แหล่งเผยแพร่</label><input type="text" name="publisher" id="edit_res_pub" class="form-control"></div><div class="row"><div class="col-6"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="publish_year" id="edit_res_year" class="form-control"></div><div class="col-6"><label class="form-label small fw-bold">Link (URL/DOI)</label><input type="text" name="url_link" id="edit_res_url" class="form-control"></div></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>

<div class="modal fade" id="modalEditCourse" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขรายวิชาที่สอน</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_course"><input type="hidden" name="course_id" id="edit_course_id"><div class="mb-3"><label class="form-label small fw-bold">รหัสวิชา</label><input type="text" name="course_code" id="edit_course_code" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อรายวิชา</label><input type="text" name="course_name" id="edit_course_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ระดับการศึกษา</label><select name="course_level" id="edit_course_level" class="form-select"><option value="ปริญญาตรี">ปริญญาตรี</option><option value="ปริญญาโท">ปริญญาโท</option><option value="ปริญญาเอก">ปริญญาเอก</option></select></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditAdv" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขที่ปรึกษาวิทยานิพนธ์</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_adv"><input type="hidden" name="adv_id" id="edit_adv_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อนิสิต</label><input type="text" name="student_name" id="edit_adv_student" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อเรื่องวิทยานิพนธ์</label><textarea name="thesis_title" id="edit_adv_thesis" class="form-control" rows="2" required></textarea></div><div class="row"><div class="col-6"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="adv_year" id="edit_adv_year" class="form-control"></div><div class="col-6"><label class="form-label small fw-bold">ประเภท</label><select name="adv_type" id="edit_adv_type" class="form-select"><option value="ที่ปรึกษาหลัก">ที่ปรึกษาหลัก</option><option value="ที่ปรึกษาร่วม">ที่ปรึกษาร่วม</option></select></div></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditCmt" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขกรรมการสอบวิทยานิพนธ์</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_cmt"><input type="hidden" name="cmt_id" id="edit_cmt_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อนิสิต</label><input type="text" name="student_name" id="edit_cmt_student" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">ชื่อเรื่องวิทยานิพนธ์</label><textarea name="thesis_title" id="edit_cmt_thesis" class="form-control" rows="2" required></textarea></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ.</label><input type="text" name="cmt_year" id="edit_cmt_year" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditAward" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขรางวัล</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_award"><input type="hidden" name="award_id" id="edit_award_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อรางวัล</label><input type="text" name="award_name" id="edit_award_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">หน่วยงานที่มอบ</label><input type="text" name="award_org" id="edit_award_org" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ. ที่ได้รับ</label><input type="text" name="award_year" id="edit_award_year" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>
<div class="modal fade" id="modalEditGrant" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form action="" method="POST" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold">แก้ไขทุนวิจัย</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="edit_grant"><input type="hidden" name="grant_id" id="edit_grant_id"><div class="mb-3"><label class="form-label small fw-bold">ชื่อโครงการวิจัย</label><input type="text" name="grant_name" id="edit_grant_name" class="form-control" required></div><div class="mb-3"><label class="form-label small fw-bold">แหล่งทุน</label><input type="text" name="grant_source" id="edit_grant_source" class="form-control"></div><div class="mb-3"><label class="form-label small fw-bold">ปี พ.ศ. ที่ได้รับ</label><input type="text" name="grant_year" id="edit_grant_year" class="form-control"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4 rounded-pill">อัปเดต</button></div></form></div></div>

<div class="modal fade" id="modalImportCSV" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="" method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg" style="border-radius: 1rem;">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-csv me-2 text-warning"></i>นำเข้าข้อมูลด้วยไฟล์ CSV</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="import_type" id="hidden_import_type" value="">
                
                <div class="alert alert-info border-0 shadow-sm small">
                    <strong>คำแนะนำ:</strong> กรุณาดาวน์โหลดไฟล์เทมเพลตตัวอย่างไปกรอกข้อมูลใน Excel จากนั้นเลือก <code>File > Save As > CSV (Comma delimited)</code> แล้วนำมาอัปโหลด
                </div>

                <div class="mb-4 text-center">
                    <a href="#" id="downloadTemplateBtn" class="btn btn-sm btn-outline-primary rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-download me-1"></i> โหลดไฟล์ Template นำเข้า
                    </a>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">อัปโหลดไฟล์ .csv ของคุณ</label>
                    <input type="file" name="csv_file" class="form-control form-control-lg shadow-sm border-0" accept=".csv" required>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white p-3">
                <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold shadow-sm py-2">เริ่มนำเข้าข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalScholarPreview" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form action="save_scholar.php" method="POST" class="modal-content border-0 shadow">
            <div class="modal-header bg-purple text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-down me-2"></i>เลือกผลงานที่ต้องการนำเข้าจาก Google Scholar</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive custom-scrollbar" style="max-height: 450px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th class="text-center" width="10%">เลือก</th>
                                <th>ชื่อผลงาน / แหล่งตีพิมพ์</th>
                                <th class="text-center" width="15%">ปี</th>
                            </tr>
                        </thead>
                        <tbody id="scholar_results_body">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <input type="hidden" name="scholar_id" id="hidden_scholar_id">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal" id="btnCancelSync">ยกเลิก</button>
                <button type="submit" class="btn btn-purple px-4 rounded-pill fw-bold shadow-sm" id="btnSaveSync" disabled>บันทึกลง CV</button>
            </div>
        </form>
    </div>
</div>

<script>
function deleteItem(table, col, val) {
    if(confirm('คุณแน่ใจว่าต้องการลบข้อมูลนี้?')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete_item"><input type="hidden" name="table_name" value="${table}"><input type="hidden" name="id_column" value="${col}"><input type="hidden" name="id_value" value="${val}">`;
        document.body.appendChild(form); form.submit();
    }
}

function openEditEdu(id, deg, inst, year) { document.getElementById('edit_edu_id').value = id; document.getElementById('edit_edu_degree').value = deg; document.getElementById('edit_edu_inst').value = inst; document.getElementById('edit_edu_year').value = year; new bootstrap.Modal(document.getElementById('modalEditEdu')).show(); }
function openEditExp(id, title, org, dur) { document.getElementById('edit_exp_id').value = id; document.getElementById('edit_exp_title').value = title; document.getElementById('edit_exp_org').value = org; document.getElementById('edit_exp_dur').value = dur; new bootstrap.Modal(document.getElementById('modalEditExp')).show(); }
function openEditSkill(id, name, level) { document.getElementById('edit_skill_id').value = id; document.getElementById('edit_skill_name').value = name; document.getElementById('edit_skill_level').value = level; new bootstrap.Modal(document.getElementById('modalEditSkill')).show(); }
function openEditCert(id, name, org, date) { document.getElementById('edit_cert_id').value = id; document.getElementById('edit_cert_name').value = name; document.getElementById('edit_cert_org').value = org; document.getElementById('edit_cert_date').value = date; new bootstrap.Modal(document.getElementById('modalEditCert')).show(); }
function openEditLang(id, name, prof) { document.getElementById('edit_lang_id').value = id; document.getElementById('edit_lang_name').value = name; document.getElementById('edit_lang_prof').value = prof; new bootstrap.Modal(document.getElementById('modalEditLang')).show(); }

function openEditRes(id, title, authors, pub, year, url) { 
    document.getElementById('edit_res_id').value = id; 
    document.getElementById('edit_res_title').value = title; 
    document.getElementById('edit_res_authors').value = authors; 
    document.getElementById('edit_res_pub').value = pub; 
    document.getElementById('edit_res_year').value = year; 
    document.getElementById('edit_res_url').value = url; 
    new bootstrap.Modal(document.getElementById('modalEditRes')).show(); 
}

function openEditCourse(id, code, name, level) { document.getElementById('edit_course_id').value = id; document.getElementById('edit_course_code').value = code; document.getElementById('edit_course_name').value = name; document.getElementById('edit_course_level').value = level; new bootstrap.Modal(document.getElementById('modalEditCourse')).show(); }
function openEditAdv(id, student, title, year, type) { document.getElementById('edit_adv_id').value = id; document.getElementById('edit_adv_student').value = student; document.getElementById('edit_adv_thesis').value = title; document.getElementById('edit_adv_year').value = year; document.getElementById('edit_adv_type').value = type; new bootstrap.Modal(document.getElementById('modalEditAdv')).show(); }
function openEditCmt(id, student, title, year) { document.getElementById('edit_cmt_id').value = id; document.getElementById('edit_cmt_student').value = student; document.getElementById('edit_cmt_thesis').value = title; document.getElementById('edit_cmt_year').value = year; new bootstrap.Modal(document.getElementById('modalEditCmt')).show(); }
function openEditAward(id, name, org, year) { document.getElementById('edit_award_id').value = id; document.getElementById('edit_award_name').value = name; document.getElementById('edit_award_org').value = org; document.getElementById('edit_award_year').value = year; new bootstrap.Modal(document.getElementById('modalEditAward')).show(); }
function openEditGrant(id, name, src, year) { document.getElementById('edit_grant_id').value = id; document.getElementById('edit_grant_name').value = name; document.getElementById('edit_grant_source').value = src; document.getElementById('edit_grant_year').value = year; new bootstrap.Modal(document.getElementById('modalEditGrant')).show(); }

// [เพิ่มใหม่] ฟังก์ชันสำหรับ Import CSV
function setImportType(type) {
    document.getElementById('hidden_import_type').value = type;
    let dlBtn = document.getElementById('downloadTemplateBtn');
    dlBtn.href = `download_cv_template.php?type=${type}`;
}

async function fetchScholarData(btnElement) {
    const scholarId = document.getElementById('scholar_id_input').value.trim();
    if (!scholarId) { 
        alert('กรุณาระบุ Scholar ID'); document.getElementById('scholar_id_input').focus(); return; 
    }

    const originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังเตรียมดึงข้อมูล...';
    btnElement.disabled = true;

    const modalPreview = new bootstrap.Modal(document.getElementById('modalScholarPreview'));
    modalPreview.show();
    
    const tbody = document.getElementById('scholar_results_body');
    const btnSave = document.getElementById('btnSaveSync');
    const btnCancel = document.getElementById('btnCancelSync');
    
    btnSave.disabled = true;
    btnCancel.disabled = true;
    document.getElementById('hidden_scholar_id').value = scholarId;

    let allData = [];
    let cstart = 0;
    const pagesize = 100;
    let hasMore = true;
    let pageCount = 1;

    try {
        while (hasMore) {
            btnElement.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> ดึงข้อมูลหน้าที่ ${pageCount}...`;
            tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-5"><i class="fa-solid fa-circle-notch fa-spin fs-1 mb-3 text-purple"></i><br>กำลังดึงข้อมูลหน้าที่ ${pageCount}...</td></tr>`;

            const response = await fetch(`fetch_scholar.php?scholar_id=${scholarId}&cstart=${cstart}&pagesize=${pagesize}`);
            const data = await response.json();

            if (data.error) { alert('เกิดข้อผิดพลาด: ' + data.error); break; }

            if (Array.isArray(data) && data.length > 0) {
                allData = allData.concat(data);
                if (data.length < 20) { hasMore = false; } 
                else {
                    cstart += data.length;
                    pageCount++;
                    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-5"><i class="fa-solid fa-hourglass-half fs-1 mb-3 text-warning"></i><br>พบข้อมูลเพิ่มเติม! กำลังรอ 5 วินาที เพื่อดึงหน้าถัดไป...</td></tr>`;
                    await new Promise(resolve => setTimeout(resolve, 5000));
                }
            } else { hasMore = false; }
        }

        tbody.innerHTML = ''; 
        if (allData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-5"><i class="fa-solid fa-folder-open fs-1 mb-3 opacity-25"></i><br>ไม่พบข้อมูลผลงานจาก Scholar ID นี้</td></tr>';
        } else {
            allData.forEach((item, index) => {
                const isExisting = item.is_existing;
                const tr = document.createElement('tr');
                if (isExisting) { tr.classList.add('bg-light', 'opacity-75'); }

                tr.innerHTML = `
                    <td class="text-center">
                        <input class="form-check-input fs-5 cursor-pointer border-secondary" type="checkbox" name="papers[${index}][selected]" value="1" ${isExisting ? 'disabled' : 'checked'}>
                        <input type="hidden" name="papers[${index}][title]" value="${item.title}">
                        <input type="hidden" name="papers[${index}][authors]" value="${item.authors || ''}">
                        <input type="hidden" name="papers[${index}][publisher]" value="${item.publisher}">
                        <input type="hidden" name="papers[${index}][publish_year]" value="${item.year}">
                        <input type="hidden" name="papers[${index}][url_link]" value="${item.link}">
                    </td>
                    <td>
                        <div class="text-secondary small fw-bold mb-1">${item.authors || ''}</div>
                        <div class="fw-bold small ${isExisting ? 'text-muted' : 'text-dark'}">${item.title}</div>
                        <div class="extra-small text-muted">${item.publisher || '-'}</div>
                        ${item.link ? `<a href="${item.link}" target="_blank" class="extra-small text-primary text-decoration-none mt-1 d-inline-block"><i class="fa-solid fa-external-link-alt"></i> ดูผลงาน</a>` : ''}
                        ${isExisting ? '<span class="badge bg-secondary ms-2 mt-1">มีในระบบแล้ว</span>' : ''}
                    </td>
                    <td class="text-center fw-bold ${isExisting ? 'text-muted' : 'text-secondary'}">${item.year || '-'}</td>
                `;
                tbody.appendChild(tr);
            });
            btnSave.disabled = false;
        }
    } catch (error) {
        console.error('Fetch Error:', error);
        alert('ไม่สามารถเชื่อมต่อ API ได้ หรือเกิดข้อผิดพลาดระหว่างดึงข้อมูล');
        modalPreview.hide();
    } finally {
        btnElement.innerHTML = originalHTML;
        btnElement.disabled = false;
        btnCancel.disabled = false;
    }
}
</script>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .bg-purple { background-color: var(--msu-purple) !important; }
    .btn-purple { background-color: var(--msu-purple); color: white; }
    .btn-outline-purple { color: var(--msu-purple); border-color: var(--msu-purple); }
    .btn-outline-purple:hover { background-color: var(--msu-purple); color: white; }
    .btn-gold { background-color: var(--msu-gold); color: white; }
    .bg-gold-subtle { background-color: #fff8e1; color: var(--msu-gold); }
    .border-bottom-dashed { border-bottom: 1px dashed #f0f0f0; }
    .cv-item { transition: background 0.2s; }
    .cv-item:hover { background-color: #fcfaff; }
    .action-btns { opacity: 0.4; transition: 0.2s; }
    .cv-item:hover .action-btns { opacity: 1; }
    .scholar-bg-icon { position: absolute; right: -15px; top: -15px; font-size: 120px; opacity: 0.1; transform: rotate(-15deg); }
    .cursor-pointer { cursor: pointer; }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #999; }
</style>

<?php require_once '../../includes/footer.php'; ?>