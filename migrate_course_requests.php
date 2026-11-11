<?php
/**
 * Migration Script: Add Approval Timestamps to course_requests table
 * MSU Music Connect - College of Music, Mahasarakham University
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/db.php';

$page_title = "Database Migration - course_requests Timestamps";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Anuphan', sans-serif; background: #f8fafc; padding: 2rem 1rem; }
        .card { border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .badge-col { font-family: monospace; font-size: 0.88rem; }
    </style>
</head>
<body>
<div class="container" style="max-width: 680px;">
    <div class="card p-4 p-md-5 bg-white">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 48px; height: 48px; background: #39048f;">
                <i class="fa-solid fa-database fs-5"></i>
            </div>
            <div>
                <h4 class="fw-bold mb-1 text-dark">Database Schema Sync</h4>
                <div class="text-muted small">ตาราง: <code>course_requests</code> (MSU Music Connect)</div>
            </div>
        </div>

        <?php
        $columns_to_add = [
            'instructor_approved_at' => [
                'type' => 'DATETIME NULL',
                'after' => 'instructor_comment',
                'desc' => 'เวลาลงนามอาจารย์ผู้สอน (ขั้นตอนที่ 1)'
            ],
            'advisor_approved_at' => [
                'type' => 'DATETIME NULL',
                'after' => 'advisor_comment',
                'desc' => 'เวลาลงนามอาจารย์ที่ปรึกษา (ขั้นตอนที่ 2)'
            ],
            'head_approved_at' => [
                'type' => 'DATETIME NULL',
                'after' => 'head_comment',
                'desc' => 'เวลาลงนามหัวหน้าภาควิชา (ขั้นตอนที่ 3)'
            ],
            'deputy_approved_at' => [
                'type' => 'DATETIME NULL',
                'after' => 'deputy_comment',
                'desc' => 'เวลาลงนามรองคณบดีฝ่ายวิชาการ (ขั้นตอนที่ 4)'
            ],
            'academic_approved_at' => [
                'type' => 'DATETIME NULL',
                'after' => 'academic_comment',
                'desc' => 'เวลาลงนามงานบริการการศึกษา (ขั้นตอนที่ 5 - อนุมัติเสร็จสิ้น)'
            ]
        ];

        $results = [];
        $has_error = false;

        try {
            // ดึงคอลัมน์ที่มีอยู่ปัจจุบัน
            $stmt = $pdo->query("DESCRIBE course_requests");
            $existing_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($columns_to_add as $col_name => $info) {
                if (in_array($col_name, $existing_cols)) {
                    $results[] = [
                        'col' => $col_name,
                        'status' => 'exists',
                        'desc' => $info['desc'],
                        'msg' => 'มีคอลัมน์นี้อยู่แล้วในฐานข้อมูล'
                    ];
                } else {
                    try {
                        $sql = "ALTER TABLE course_requests ADD COLUMN `{$col_name}` {$info['type']} AFTER `{$info['after']}`";
                        $pdo->exec($sql);
                        $results[] = [
                            'col' => $col_name,
                            'status' => 'added',
                            'desc' => $info['desc'],
                            'msg' => 'เพิ่มคอลัมน์สำเร็จเรียบร้อย'
                        ];
                    } catch (Exception $e) {
                        $has_error = true;
                        $results[] = [
                            'col' => $col_name,
                            'status' => 'error',
                            'desc' => $info['desc'],
                            'msg' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            $has_error = true;
            echo '<div class="alert alert-danger">ไม่สามารถเข้าถึงตาราง course_requests: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>

        <div class="mb-4">
            <h6 class="fw-bold mb-3 text-secondary">ผลการตรวจสอบโครงสร้างฐานข้อมูล:</h6>
            <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                <?php foreach ($results as $res): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                        <div>
                            <div class="fw-semibold text-dark">
                                <span class="badge-col text-primary"><?= htmlspecialchars($res['col']) ?></span>
                            </div>
                            <div class="text-muted extra-small" style="font-size: 0.8rem;"><?= htmlspecialchars($res['desc']) ?></div>
                        </div>
                        <div>
                            <?php if ($res['status'] === 'added'): ?>
                                <span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fa-solid fa-check me-1"></i>เพิ่มสำเร็จ</span>
                            <?php elseif ($res['status'] === 'exists'): ?>
                                <span class="badge bg-secondary-subtle text-secondary border px-2 py-1"><i class="fa-solid fa-circle-check me-1"></i>พร้อมใช้งาน</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= htmlspecialchars($res['msg']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($has_error): ?>
            <div class="alert alert-warning mb-4">
                <div class="fw-bold mb-1"><i class="fa-solid fa-lightbulb me-1"></i> หากติดสิทธิ์การ ALTER TABLE:</div>
                <div class="small">ท่านสามารถคัดลอกคำสั่ง SQL ด้านล่างไปรันโดยตรงใน phpMyAdmin บน Host จริงได้ทันที:</div>
                <pre class="bg-dark text-light p-3 rounded-3 mt-2 small" style="user-select: all;">ALTER TABLE `course_requests`
ADD COLUMN `instructor_approved_at` DATETIME NULL AFTER `instructor_comment`,
ADD COLUMN `advisor_approved_at` DATETIME NULL AFTER `advisor_comment`,
ADD COLUMN `head_approved_at` DATETIME NULL AFTER `head_comment`,
ADD COLUMN `deputy_approved_at` DATETIME NULL AFTER `deputy_comment`,
ADD COLUMN `academic_approved_at` DATETIME NULL AFTER `academic_comment`;</pre>
            </div>
        <?php else: ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
                <i class="fa-solid fa-circle-check fs-5"></i>
                <div>ฐานข้อมูลพร้อมใช้งานกับระบบพิจารณาอนุมัติคำร้องวิชาการ 100% แล้ว</div>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center">
            <a href="index.php?view=executive" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้า Executive Hub
            </a>
            <a href="migrate_course_requests.php" class="btn text-white rounded-pill px-4" style="background: #39048f;">
                <i class="fa-solid fa-rotate-right me-1"></i> ตรวจสอบอีกครั้ง
            </a>
        </div>
    </div>
</div>
</body>
</html>
