<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. เช็คสิทธิ์การเข้าถึง
$view_roles = ['admin', 'หัวหน้าสำนักงาน', 'งานบุคคล', 'งานแผน', 'รองคณบดีฝ่ายบริหาร', 'คณบดี', 'รักษาการคณบดี'];
if (!hasRole($view_roles)) {
    exit('Permission Denied');
}

$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : 'all';
$filter_user = isset($_GET['filter_user']) ? $_GET['filter_user'] : 'all';

$where_clauses = ["1=1"];
$params = [];
if ($filter_year !== 'all') {
    $where_clauses[] = "tr.fiscal_year = :filter_year";
    $params['filter_year'] = $filter_year;
}
if ($filter_user !== 'all') {
    $where_clauses[] = "tr.user_id = :filter_user";
    $params['filter_user'] = $filter_user;
}
$where_sql = implode(" AND ", $where_clauses);

$stmt_list = $pdo->prepare("
    SELECT tr.report_id, tr.project_name, tr.start_date, tr.end_date, tr.budget_used, tr.status, tr.created_at, tr.location, tr.report_type,
           (IFNULL(tr.est_budget_travel, 0) + IFNULL(tr.est_budget_accom, 0) + IFNULL(tr.est_budget_allowance, 0) + IFNULL(tr.est_budget_regis, 0) + IFNULL(tr.est_budget_other, 0)) as est_budget_total,
           u.first_name, u.last_name, d.dept_name,
           (IFNULL(tr.act_budget_travel, 0) + IFNULL(tr.act_budget_accom, 0) + IFNULL(tr.act_budget_allowance, 0) + IFNULL(tr.act_budget_regis, 0) + IFNULL(tr.act_budget_other, 0)) as act_budget_total
    FROM training_reports tr
    JOIN users u ON tr.user_id = u.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE $where_sql
    ORDER BY tr.created_at DESC
");
$stmt_list->execute($params);
$reports_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// กำหนด Header สำหรับ CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="training_reports_' . date('Ymd_His') . '.csv"');

// เปิด Output Stream และพิมพ์ BOM สำหรับเปิดใน Excel ได้แบบไม่เพี้ยน (UTF-8 BOM)
$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF");

// กำหนดหัวคอลัมน์
fputcsv($output, ['รหัสรายการ', 'ชื่อโครงการ', 'สถานที่', 'ประเภท', 'ผู้เข้าร่วม', 'หน่วยงาน', 'วันที่เริ่ม', 'วันที่สิ้นสุด', 'งบประมาณขออนุมัติ', 'งบประมาณเบิกจ่ายจริง', 'สถานะ', 'วันที่ยื่น']);

// วนลูปข้อมูลและเขียนลงไฟล์
foreach ($reports_list as $row) {
    // แปลงสถานะเป็นภาษาไทย
    $status_text = $row['status'];
    switch ($row['status']) {
        case 'pending': $status_text = 'รออนุมัติ'; break;
        case 'approved': $status_text = 'อนุมัติแล้ว (รอรายงาน)'; break;
        case 'rejected': $status_text = 'ไม่อนุมัติ'; break;
        case 'reported': $status_text = 'รายงานผลแล้ว'; break;
    }
    
    // แปลงวันที่ให้อ่านง่าย
    $start_date = !empty($row['start_date']) ? date('d/m/Y', strtotime($row['start_date'])) : '-';
    $end_date = !empty($row['end_date']) ? date('d/m/Y', strtotime($row['end_date'])) : '-';
    $created_at = !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-';

    fputcsv($output, [
        $row['report_id'],
        $row['project_name'],
        $row['location'],
        $row['report_type'] ?: 'ไปราชการ',
        $row['first_name'] . ' ' . $row['last_name'],
        $row['dept_name'],
        $start_date,
        $end_date,
        $row['est_budget_total'],
        $row['act_budget_total'],
        $status_text,
        $created_at
    ]);
}
fclose($output);
exit();
?>
