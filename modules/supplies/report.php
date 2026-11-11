<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. ตรวจสอบสิทธิ์ผู้เข้าชม (แอดมิน, หัวหน้าสำนักงาน, รองบริหาร, งานพัสดุ, คณบดี)
$allowed_roles = ['admin', 'หัวหน้าสำนักงาน', 'รองบริหาร', 'งานพัสดุ', 'คณบดี'];
if (!hasRole($allowed_roles)) {
    $_SESSION['msg'] = "ปฏิเสธการเข้าถึง: ท่านไม่มีสิทธิ์ดูภาพรวมระบบพัสดุ";
    $_SESSION['msg_type'] = "danger";
    header("Location: ../../index.php");
    exit();
}

// ---------------------------------------------------------
// AJAX Endpoint: ดึงประวัติรายการที่นำออกของวัสดุรายชิ้น
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_item_issued_history') {
    header('Content-Type: application/json; charset=utf-8');
    $item_id = intval($_GET['item_id'] ?? 0);
    $fy = $_GET['fy'] ?? 'all';
    
    $where_fy = ($fy === 'all') ? "1=1" : "r.fiscal_year = " . (int)$fy;
    
    $stmt_hist = $pdo->prepare("
        SELECT 
            d.issued_qty,
            r.request_id,
            r.fiscal_year,
            r.request_date,
            COALESCE(r.issue_date, r.request_date) AS issue_date,
            r.request_reason,
            u.first_name, u.last_name, u.position,
            issuer.first_name AS issuer_fname, issuer.last_name AS issuer_lname
        FROM supplies_request_details d
        JOIN supplies_requests r ON d.request_id = r.request_id
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN users issuer ON r.supply_issuer_id = issuer.user_id
        WHERE d.item_id = ? AND r.supply_status = 'issued' AND d.issued_qty > 0 AND {$where_fy}
        ORDER BY COALESCE(r.issue_date, r.request_date) DESC, d.detail_id DESC
    ");
    $stmt_hist->execute([$item_id]);
    $history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

    // ดึงข้อมูลชื่อและหน่วยนับของวัสดุ
    $stmt_it = $pdo->prepare("SELECT item_name, category, unit_name, stock_qty, received_date FROM supplies_items WHERE item_id = ?");
    $stmt_it->execute([$item_id]);
    $item_info = $stmt_it->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'item' => $item_info,
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. ดึงปีงบประมาณปัจจุบันที่ Active จากตาราง fiscal_years
$stmt_active = $pdo->query("SELECT year_name FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$default_fy = $stmt_active->fetchColumn();
if (!$default_fy) { $default_fy = date('Y') + 543; }

// รับค่าการกรองปีงบประมาณจาก URL (ถ้าไม่มีให้ใช้ปีที่ Active)
$filter_fy = isset($_GET['fy']) ? $_GET['fy'] : $default_fy;

// ดึงรายการปีงบประมาณทั้งหมดที่มีในระบบมาทำตัวเลือก (Filter)
$stmt_years = $pdo->query("SELECT year_name FROM fiscal_years ORDER BY year_name DESC");
$available_years = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

// รับค่าแท็บการแสดงผล:
// 'stock' = วัสดุคงเหลือ (รับเข้า - นำออก - คงคลัง)
// 'issued' = รายการที่นำออกทั้งหมด (Issue Transaction Log)
// 'requests' = ประวัติการขอเบิกตามบิล
$tab = $_GET['tab'] ?? 'stock';
$filter_cat = trim($_GET['cat'] ?? 'all');
$filter_status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// ดึงรายการประเภทวัสดุที่มีในระบบ
$stmt_cats = $pdo->query("SELECT DISTINCT category FROM supplies_items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = $stmt_cats->fetchAll(PDO::FETCH_COLUMN);

// ฟังก์ชันแปลงวันที่ภาษาไทยสั้น
function formatThaiDateShort($dateStr) {
    if (empty($dateStr) || $dateStr == '0000-00-00' || $dateStr == '0000-00-00 00:00:00') return '-';
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return '-';
    $thai_months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $day = date('d', $timestamp);
    $month = $thai_months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;
    return "{$day} {$month} {$year}";
}

// ---------------------------------------------------------
// ระบบ EXPORT CSV
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    
    if ($tab === 'stock') {
        // --- Export รายงานวัสดุคงเหลือ (รับเข้า - นำออก - คงคลัง) ---
        $filename = "supplies_stock_balance_FY" . $filter_fy . "_" . date('Ymd_Hi') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 สำหรับภาษาไทยใน Excel
        
        fputcsv($output, ['ลำดับ', 'ประเภทวัสดุ', 'ชื่อรายการวัสดุ', 'วันที่นำเข้า', 'ยอดนำเข้า (รับเข้า)', 'วันที่นำออกล่าสุด', 'ยอดนำออก (จ่ายออก)', 'คงคลัง (คงเหลือ)', 'หน่วยนับ', 'สถานะ']);
        
        $where_csv = [];
        $params_csv = [];
        
        if (!empty($filter_cat) && $filter_cat !== 'all') {
            $where_csv[] = "i.category = ?";
            $params_csv[] = $filter_cat;
        }
        if (!empty($search)) {
            $where_csv[] = "(i.item_name LIKE ? OR i.category LIKE ?)";
            $params_csv[] = "%{$search}%";
            $params_csv[] = "%{$search}%";
        }
        $where_csv_sql = !empty($where_csv) ? "WHERE " . implode(" AND ", $where_csv) : "";
        
        $fy_cond = ($filter_fy === 'all') ? "1=1" : "r.fiscal_year = " . (int)$filter_fy;
        
        $sql_csv = "
            SELECT 
                i.item_id,
                i.item_name,
                i.category,
                i.stock_qty,
                i.received_date,
                i.unit_name,
                i.is_active,
                COALESCE(SUM(CASE WHEN r.supply_status = 'issued' THEN d.issued_qty ELSE 0 END), 0) AS total_out_all,
                COALESCE(SUM(CASE WHEN r.supply_status = 'issued' AND {$fy_cond} THEN d.issued_qty ELSE 0 END), 0) AS fy_out,
                MAX(CASE WHEN r.supply_status = 'issued' AND d.issued_qty > 0 AND {$fy_cond} THEN COALESCE(r.issue_date, r.request_date) ELSE NULL END) AS last_issue_date
            FROM supplies_items i
            LEFT JOIN supplies_request_details d ON i.item_id = d.item_id
            LEFT JOIN supplies_requests r ON d.request_id = r.request_id
            {$where_csv_sql}
            GROUP BY i.item_id, i.item_name, i.category, i.stock_qty, i.received_date, i.unit_name, i.is_active
            ORDER BY i.category ASC, i.item_name ASC
        ";
        $stmt_export_stock = $pdo->prepare($sql_csv);
        $stmt_export_stock->execute($params_csv);
        
        $idx = 1;
        while ($row = $stmt_export_stock->fetch(PDO::FETCH_ASSOC)) {
            $out_qty = ($filter_fy === 'all') ? (int)$row['total_out_all'] : (int)$row['fy_out'];
            $stock_qty = (int)$row['stock_qty'];
            $in_qty = $stock_qty + (int)$row['total_out_all'];
            
            $status_th = 'พร้อมจ่าย';
            if ($row['is_active'] == 0) $status_th = 'ปิดใช้งาน';
            elseif ($stock_qty <= 0) $status_th = 'สินค้าหมด';
            elseif ($stock_qty <= 5) $status_th = 'สต็อกเหลือน้อย';
            
            fputcsv($output, [
                $idx++,
                $row['category'],
                $row['item_name'],
                !empty($row['received_date']) ? date('d/m/Y', strtotime($row['received_date'])) : '-',
                $in_qty,
                !empty($row['last_issue_date']) ? date('d/m/Y H:i', strtotime($row['last_issue_date'])) : '-',
                $out_qty,
                $stock_qty,
                $row['unit_name'],
                $status_th
            ]);
        }
        fclose($output);
        exit();

    } elseif ($tab === 'issued') {
        // --- Export รายการที่นำออกทั้งหมด (Issued Transactions) ---
        $filename = "supplies_issued_transactions_FY" . $filter_fy . "_" . date('Ymd_Hi') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        fputcsv($output, ['ลำดับ', 'วันที่นำออก', 'เลขที่คำขอเบิก', 'ปีงบประมาณ', 'ประเภทวัสดุ', 'ชื่อรายการวัสดุที่นำออก', 'จำนวนที่นำออก', 'หน่วยนับ', 'ผู้ขอเบิก', 'ตำแหน่ง', 'เหตุผลการเบิก', 'ผู้จ่ายพัสดุ']);
        
        $where_iss = ["r.supply_status = 'issued'", "d.issued_qty > 0"];
        $params_iss = [];
        if ($filter_fy !== 'all') {
            $where_iss[] = "r.fiscal_year = ?";
            $params_iss[] = (int)$filter_fy;
        }
        if (!empty($filter_cat) && $filter_cat !== 'all') {
            $where_iss[] = "i.category = ?";
            $params_iss[] = $filter_cat;
        }
        if (!empty($search)) {
            $where_iss[] = "(i.item_name LIKE ? OR i.category LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
            $params_iss[] = "%{$search}%";
            $params_iss[] = "%{$search}%";
            $params_iss[] = "%{$search}%";
            $params_iss[] = "%{$search}%";
        }
        $where_iss_sql = "WHERE " . implode(" AND ", $where_iss);

        $stmt_export_iss = $pdo->prepare("
            SELECT 
                d.detail_id, d.request_id, d.issued_qty,
                i.item_name, i.category, i.unit_name,
                r.fiscal_year, r.request_date,
                COALESCE(r.issue_date, r.request_date) AS issue_date,
                r.request_reason,
                u.first_name, u.last_name, u.position,
                issuer.first_name AS issuer_fname, issuer.last_name AS issuer_lname
            FROM supplies_request_details d
            JOIN supplies_items i ON d.item_id = i.item_id
            JOIN supplies_requests r ON d.request_id = r.request_id
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN users issuer ON r.supply_issuer_id = issuer.user_id
            {$where_iss_sql}
            ORDER BY COALESCE(r.issue_date, r.request_date) DESC, d.detail_id DESC
        ");
        $stmt_export_iss->execute($params_iss);
        
        $idx = 1;
        while ($row = $stmt_export_iss->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $idx++,
                date('d/m/Y H:i', strtotime($row['issue_date'])),
                "REQ-" . str_pad($row['request_id'], 4, '0', STR_PAD_LEFT),
                $row['fiscal_year'],
                $row['category'],
                $row['item_name'],
                $row['issued_qty'],
                $row['unit_name'],
                $row['first_name'] . ' ' . $row['last_name'],
                $row['position'],
                $row['request_reason'],
                trim(($row['issuer_fname'] ?? '') . ' ' . ($row['issuer_lname'] ?? '')) ?: '-'
            ]);
        }
        fclose($output);
        exit();

    } else {
        // --- Export ประวัติการขอเบิกพัสดุเดิม ---
        $filename = "supplies_report_FY" . $filter_fy . "_" . date('Ymd_Hi') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        fputcsv($output, ['ปีงบประมาณ', 'เลขที่คำขอ', 'วันที่ขอเบิก', 'ชื่อผู้เบิก', 'ตำแหน่ง', 'เหตุผลการเบิก', 'รายการวัสดุ', 'จำนวนที่ขอ', 'จำนวนที่จ่ายจริง', 'หน่วยนับ', 'สถานะหัวหน้า', 'สถานะพัสดุ']);
        
        $fy_csv_cond = ($filter_fy === 'all') ? "1=1" : "r.fiscal_year = " . (int)$filter_fy;
        $stmt_csv = $pdo->prepare("
            SELECT r.fiscal_year, r.request_id, r.request_date, r.request_reason, r.head_status, r.supply_status,
                   u.first_name, u.last_name, u.position,
                   d.request_qty, d.issued_qty, i.item_name, i.unit_name
            FROM supplies_requests r
            JOIN users u ON r.user_id = u.user_id
            JOIN supplies_request_details d ON r.request_id = d.request_id
            JOIN supplies_items i ON d.item_id = i.item_id
            WHERE {$fy_csv_cond}
            ORDER BY r.request_date DESC
        ");
        $stmt_csv->execute();
        
        while ($row = $stmt_csv->fetch(PDO::FETCH_ASSOC)) {
            $head_status_th = ($row['head_status'] == 'approved') ? 'อนุมัติ' : (($row['head_status'] == 'rejected') ? 'ไม่อนุมัติ' : 'รอพิจารณา');
            $supply_status_th = ($row['supply_status'] == 'issued') ? 'จ่ายของแล้ว' : (($row['supply_status'] == 'rejected') ? 'ยกเลิกจ่าย' : 'รอพิจารณา');
            if ($row['head_status'] == 'rejected') $supply_status_th = '-';

            fputcsv($output, [
                $row['fiscal_year'],
                "REQ-" . str_pad($row['request_id'], 4, '0', STR_PAD_LEFT),
                date('d/m/Y H:i', strtotime($row['request_date'])),
                $row['first_name'] . ' ' . $row['last_name'],
                $row['position'],
                $row['request_reason'],
                $row['item_name'],
                $row['request_qty'],
                $row['issued_qty'] ?? 0,
                $row['unit_name'],
                $head_status_th,
                $supply_status_th
            ]);
        }
        fclose($output);
        exit();
    }
}

// ---------------------------------------------------------
// 3. เตรียมข้อมูลสำหรับแท็บ "วัสดุคงเหลือ (Stock Balance)"
// ---------------------------------------------------------
$stock_items = [];
$total_skus = 0;
$total_in_all = 0;
$total_out_all = 0;
$total_stock_all = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;

if ($tab === 'stock') {
    $where_stock = [];
    $params_stock = [];

    if (!empty($filter_cat) && $filter_cat !== 'all') {
        $where_stock[] = "i.category = ?";
        $params_stock[] = $filter_cat;
    }
    if (!empty($search)) {
        $where_stock[] = "(i.item_name LIKE ? OR i.category LIKE ?)";
        $params_stock[] = "%{$search}%";
        $params_stock[] = "%{$search}%";
    }
    $where_stock_sql = !empty($where_stock) ? "WHERE " . implode(" AND ", $where_stock) : "";

    $fy_sql_cond = ($filter_fy === 'all') ? "1=1" : "r.fiscal_year = " . (int)$filter_fy;

    $sql_stock = "
        SELECT 
            i.item_id,
            i.item_name,
            i.category,
            i.stock_qty,
            i.received_date,
            i.unit_name,
            i.is_active,
            COALESCE(SUM(CASE WHEN r.supply_status = 'issued' THEN d.issued_qty ELSE 0 END), 0) AS total_out_cumulative,
            COALESCE(SUM(CASE WHEN r.supply_status = 'issued' AND {$fy_sql_cond} THEN d.issued_qty ELSE 0 END), 0) AS fy_out,
            MAX(CASE WHEN r.supply_status = 'issued' AND d.issued_qty > 0 AND {$fy_sql_cond} THEN COALESCE(r.issue_date, r.request_date) ELSE NULL END) AS last_issue_date,
            COUNT(DISTINCT CASE WHEN r.supply_status = 'issued' AND d.issued_qty > 0 AND {$fy_sql_cond} THEN r.request_id ELSE NULL END) AS issue_count
        FROM supplies_items i
        LEFT JOIN supplies_request_details d ON i.item_id = d.item_id
        LEFT JOIN supplies_requests r ON d.request_id = r.request_id
        {$where_stock_sql}
        GROUP BY i.item_id, i.item_name, i.category, i.stock_qty, i.received_date, i.unit_name, i.is_active
        ORDER BY i.category ASC, i.item_name ASC
    ";
    $stmt_stock = $pdo->prepare($sql_stock);
    $stmt_stock->execute($params_stock);
    $all_stock_items = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณสรุปและกรองตามสถานะสต็อก (ถ้ามีการเลือก)
    foreach ($all_stock_items as $item) {
        $stock = (int)$item['stock_qty'];
        $cumulative_out = (int)$item['total_out_cumulative'];
        $fy_out = (int)$item['fy_out'];
        $selected_out = ($filter_fy === 'all') ? $cumulative_out : $fy_out;
        $total_in = $stock + $cumulative_out; // ยอดนำเข้ารวม

        $is_out_of_stock = ($stock <= 0);
        $is_low_stock = ($stock > 0 && $stock <= 5);

        if ($is_out_of_stock) $out_of_stock_count++;
        if ($is_low_stock) $low_stock_count++;

        $total_skus++;
        $total_in_all += $total_in;
        $total_out_all += $selected_out;
        $total_stock_all += $stock;

        // กรองตามสถานะสต็อก
        if ($filter_status === 'available' && ($is_out_of_stock || $is_low_stock)) continue;
        if ($filter_status === 'low' && !$is_low_stock) continue;
        if ($filter_status === 'out' && !$is_out_of_stock) continue;

        $item['calc_in'] = $total_in;
        $item['calc_out'] = $selected_out;
        $stock_items[] = $item;
    }
}

// ---------------------------------------------------------
// 4. เตรียมข้อมูลสำหรับแท็บ "รายการที่นำออกทั้งหมด (Issued Transactions)"
// ---------------------------------------------------------
$issued_transactions = [];
$total_issued_qty_all = 0;
$total_issued_items_count = 0;

if ($tab === 'issued') {
    $where_iss = ["r.supply_status = 'issued'", "d.issued_qty > 0"];
    $params_iss = [];
    if ($filter_fy !== 'all') {
        $where_iss[] = "r.fiscal_year = ?";
        $params_iss[] = (int)$filter_fy;
    }
    if (!empty($filter_cat) && $filter_cat !== 'all') {
        $where_iss[] = "i.category = ?";
        $params_iss[] = $filter_cat;
    }
    if (!empty($search)) {
        $where_iss[] = "(i.item_name LIKE ? OR i.category LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $params_iss[] = "%{$search}%";
        $params_iss[] = "%{$search}%";
        $params_iss[] = "%{$search}%";
        $params_iss[] = "%{$search}%";
    }
    $where_iss_sql = "WHERE " . implode(" AND ", $where_iss);

    $stmt_iss_list = $pdo->prepare("
        SELECT 
            d.detail_id, d.request_id, d.issued_qty,
            i.item_id, i.item_name, i.category, i.unit_name,
            r.fiscal_year, r.request_date,
            COALESCE(r.issue_date, r.request_date) AS issue_date,
            r.request_reason,
            u.first_name, u.last_name, u.position,
            issuer.first_name AS issuer_fname, issuer.last_name AS issuer_lname
        FROM supplies_request_details d
        JOIN supplies_items i ON d.item_id = i.item_id
        JOIN supplies_requests r ON d.request_id = r.request_id
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN users issuer ON r.supply_issuer_id = issuer.user_id
        {$where_iss_sql}
        ORDER BY COALESCE(r.issue_date, r.request_date) DESC, d.detail_id DESC
    ");
    $stmt_iss_list->execute($params_iss);
    $issued_transactions = $stmt_iss_list->fetchAll(PDO::FETCH_ASSOC);

    foreach ($issued_transactions as $tx) {
        $total_issued_qty_all += (int)$tx['issued_qty'];
    }
    $total_issued_items_count = count($issued_transactions);
}

// ---------------------------------------------------------
// 5. เตรียมข้อมูลสำหรับแท็บ "ประวัติการขอเบิก (Requests List)"
// ---------------------------------------------------------
$total_requests = 0;
$pending_requests = 0;
$issued_requests = 0;
$rejected_requests = 0;
$requests_list = [];

if ($tab === 'requests') {
    $fy_filter_sql = ($filter_fy === 'all') ? "1=1" : "fiscal_year = " . (int)$filter_fy;

    $stmt_total = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE {$fy_filter_sql}");
    $total_requests = $stmt_total->fetchColumn();

    $stmt_pending = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE {$fy_filter_sql} AND (head_status = 'pending' OR (head_status = 'approved' AND supply_status = 'pending'))");
    $pending_requests = $stmt_pending->fetchColumn();

    $stmt_issued = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE {$fy_filter_sql} AND supply_status = 'issued'");
    $issued_requests = $stmt_issued->fetchColumn();

    $stmt_rejected = $pdo->query("SELECT COUNT(*) FROM supplies_requests WHERE {$fy_filter_sql} AND (head_status = 'rejected' OR supply_status = 'rejected')");
    $rejected_requests = $stmt_rejected->fetchColumn();

    $stmt_list = $pdo->query("
        SELECT r.request_id, r.request_date, r.head_status, r.supply_status, r.request_reason, r.fiscal_year,
               u.first_name, u.last_name,
               (SELECT COUNT(*) FROM supplies_request_details WHERE request_id = r.request_id) as item_count
        FROM supplies_requests r
        JOIN users u ON r.user_id = u.user_id
        WHERE {$fy_filter_sql}
        ORDER BY r.request_date DESC
    ");
    $requests_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
}

// ฟังก์ชัน Badge สถานะการเบิกเดิม
function getBadge($status, $type) {
    if ($status == 'pending') return '<span class="badge bg-warning-subtle text-dark border border-warning px-3 py-1 rounded-pill fw-normal shadow-sm">รอพิจารณา</span>';
    if ($type == 'head' && $status == 'approved') return '<span class="badge bg-primary-subtle text-primary border border-primary px-3 py-1 rounded-pill fw-normal shadow-sm">อนุมัติ</span>';
    if ($type == 'head' && $status == 'rejected') return '<span class="badge bg-danger-subtle text-danger border border-danger px-3 py-1 rounded-pill fw-normal shadow-sm">ไม่อนุมัติ</span>';
    if ($type == 'supply' && $status == 'issued') return '<span class="badge bg-success-subtle text-success border border-success px-3 py-1 rounded-pill fw-normal shadow-sm">จ่ายแล้ว</span>';
    if ($type == 'supply' && $status == 'rejected') return '<span class="badge bg-danger-subtle text-danger border border-danger px-3 py-1 rounded-pill fw-normal shadow-sm">ยกเลิก</span>';
    return '<span class="badge bg-light text-muted border px-3 py-1 rounded-pill fw-normal shadow-sm">-</span>';
}

// ฟังก์ชันระบุสี Badge ของประเภทวัสดุ
function getCategoryBadge($category) {
    switch ($category) {
        case 'วัสดุคอมพิวเตอร์':
            return '<span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1"><i class="fa-solid fa-laptop-code me-1"></i> ' . htmlspecialchars($category) . '</span>';
        case 'วัสดุสำนักงาน':
            return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1"><i class="fa-solid fa-pen-ruler me-1"></i> ' . htmlspecialchars($category) . '</span>';
        case 'วัสดุงานบ้าน':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1"><i class="fa-solid fa-broom me-1"></i> ' . htmlspecialchars($category) . '</span>';
        case 'วัสดุไฟฟ้า':
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1"><i class="fa-solid fa-bolt me-1"></i> ' . htmlspecialchars($category) . '</span>';
        default:
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1"><i class="fa-solid fa-box me-1"></i> ' . htmlspecialchars($category ?? 'ทั่วไป') . '</span>';
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
    /* 🎨 CUSTOM BRAND COLORS (คุมโทน ม่วง-ทอง) */
    .text-purple { color: #4b0082 !important; }
    .bg-purple { background-color: #4b0082 !important; color: white; }
    .bg-purple-subtle { background-color: #f3e5f5 !important; }
    .border-purple-subtle { border-color: #e1bee7 !important; }
    
    .text-gold { color: #c5a028 !important; }
    .bg-gold { background-color: #c5a028 !important; color: white; }
    .bg-gold-subtle { background-color: #fcf4d9 !important; }

    .btn-ghost-view {
        color: #94a3b8;
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 50%;
        text-decoration: none; transition: all 0.2s;
        background-color: #f8fafc; border: 1px solid #e2e8f0;
    }
    .btn-ghost-view:hover {
        background-color: #f3e5f5; border-color: #4b0082; color: #4b0082;
        transform: translateY(-2px);
    }
    .extra-small { font-size: 0.75rem; }
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.08) !important; }

    /* Print Styles */
    @media print {
        .sidebar, .navbar-custom, .d-print-none, .btn, form, footer { display: none !important; }
        main { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border-collapse: collapse !important; }
        .table th, .table td { border: 1px solid #ddd !important; font-size: 10pt !important; padding: 5px !important; }
        .print-header { display: block !important; margin-bottom: 20px; }
    }
    .print-header { display: none; }
</style>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <!-- Print Official Header -->
    <div class="print-header text-center">
        <h4 class="fw-bold mb-1">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</h4>
        <h5 class="fw-bold text-dark">
            <?php 
                if ($tab === 'stock') echo "รายงานวัสดุคงคลัง (รับเข้า - นำออก - คงเหลือ) ประจำปีงบประมาณ " . htmlspecialchars($filter_fy);
                elseif ($tab === 'issued') echo "รายงานประวัติรายการที่นำออก (เบิกจ่าย) ประจำปีงบประมาณ " . htmlspecialchars($filter_fy);
                else echo "รายงานประวัติคำขอเบิกพัสดุ ประจำปีงบประมาณ " . htmlspecialchars($filter_fy);
            ?>
        </h5>
        <p class="small text-muted mb-0">พิมพ์รายงานเมื่อ: <?= date('d/m/Y H:i'); ?> น.</p>
        <hr>
    </div>

    <!-- Page Header (Top Navigation & Switcher) -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom d-print-none">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1">
                <i class="fa-solid fa-chart-pie me-2 text-gold"></i>ระบบรายงานพัสดุและคลังวัสดุ
            </h1>
            <p class="text-muted mb-0">รายงานสรุปข้อมูลวัสดุคงเหลือ วันที่นำเข้า วันที่นำออก และประวัติการเบิก ประจำปีงบประมาณ</p>
        </div>
        
        <div class="d-flex gap-2 align-items-center mt-3 mt-md-0 flex-wrap">
            <!-- Filter Fiscal Year Form -->
            <form action="" method="GET" class="d-flex align-items-center bg-white border border-light rounded-pill px-3 py-1 shadow-sm">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab); ?>">
                <?php if ($tab === 'stock' || $tab === 'issued'): ?>
                    <input type="hidden" name="cat" value="<?= htmlspecialchars($filter_cat); ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status); ?>">
                <?php endif; ?>
                <i class="fa-regular fa-calendar text-muted me-2"></i>
                <span class="text-muted small fw-bold me-2 text-nowrap">ปีงบฯ:</span>
                <select name="fy" class="form-select form-select-sm border-0 fw-bold text-purple bg-transparent pe-4" onchange="this.form.submit()" style="box-shadow: none; cursor: pointer;">
                    <option value="all" <?= ($filter_fy === 'all') ? 'selected' : ''; ?>>ทุกปีงบประมาณ</option>
                    <?php foreach($available_years as $year): ?>
                        <option value="<?= $year; ?>" <?= ($filter_fy == $year) ? 'selected' : ''; ?>><?= $year; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            
            <!-- Export CSV Button -->
            <a href="?export=csv&tab=<?= htmlspecialchars($tab); ?>&fy=<?= htmlspecialchars($filter_fy); ?><?= ($filter_cat !== 'all') ? '&cat=' . urlencode($filter_cat) : ''; ?><?= (!empty($search)) ? '&search=' . urlencode($search) : ''; ?>" class="btn bg-success text-white rounded-pill px-3 py-2 shadow-sm fw-bold d-flex align-items-center hover-lift" title="ส่งออกข้อมูลเป็น CSV (Excel)">
                <i class="fa-solid fa-file-csv me-2 fs-5"></i> Export CSV
            </a>

            <!-- Print Button -->
            <button type="button" onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-3 py-2 shadow-sm fw-bold hover-lift" title="พิมพ์รายงาน">
                <i class="fa-solid fa-print me-1"></i> พิมพ์
            </button>
        </div>
    </div>

    <!-- Main Navigation Tabs (3 Tabs: Stock Balance, Issued Transactions, Requests) -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 d-print-none">
        <ul class="nav nav-pills gap-2 p-1 bg-white rounded-pill shadow-sm border d-inline-flex flex-wrap">
            <li class="nav-item">
                <a class="nav-link rounded-pill px-4 py-2 fw-bold <?= ($tab === 'stock') ? 'active bg-purple text-white shadow-sm' : 'text-secondary'; ?>" href="?tab=stock&fy=<?= htmlspecialchars($filter_fy); ?>">
                    <i class="fa-solid fa-boxes-stacked me-2"></i>1. วัสดุคงเหลือ (รับเข้า - นำออก - คงคลัง)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill px-4 py-2 fw-bold <?= ($tab === 'issued') ? 'active bg-purple text-white shadow-sm' : 'text-secondary'; ?>" href="?tab=issued&fy=<?= htmlspecialchars($filter_fy); ?>">
                    <i class="fa-solid fa-dolly me-2"></i>2. ประวัติรายการที่นำออกทั้งหมด
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link rounded-pill px-4 py-2 fw-bold <?= ($tab === 'requests') ? 'active bg-purple text-white shadow-sm' : 'text-secondary'; ?>" href="?tab=requests&fy=<?= htmlspecialchars($filter_fy); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>3. ประวัติคำขอเบิกตามบิล
                </a>
            </li>
        </ul>

        <div class="text-muted small">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> ปีงบประมาณ: 
            <span class="badge bg-purple text-white rounded-pill px-3 py-1"><?= ($filter_fy === 'all') ? 'ทุกปีงบประมาณ' : 'ปีงบฯ ' . htmlspecialchars($filter_fy); ?></span>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 1: รายงานวัสดุคงเหลือ เข้านำ นำออก คงคลัง เป็นประเภทชื่อรายการวัสดุ -->
    <!-- ================================================================= -->
    <?php if ($tab === 'stock'): ?>

        <!-- KPI Cards สำหรับวัสดุคงเหลือ -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">รายการวัสดุทั้งหมด</p>
                            <h3 class="text-dark fw-bold mb-0"><?= number_format($total_skus); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                            <div class="extra-small text-muted mt-1"><i class="fa-solid fa-tags text-purple me-1"></i><?= count($categories); ?> หมวดหมู่วัสดุ</div>
                        </div>
                        <div class="stat-icon bg-purple-subtle text-purple rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-info">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">ยอดรับเข้ารวม (นำเข้า)</p>
                            <h3 class="text-info fw-bold mb-0"><?= number_format($total_in_all); ?> <small class="fs-6 text-muted fw-normal">ชิ้น/หน่วย</small></h3>
                            <div class="extra-small text-muted mt-1">ยอดรวมรับเข้าสะสมทั้งหมด</div>
                        </div>
                        <div class="stat-icon bg-info-subtle text-info rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-dolly"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-warning">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">ยอดจ่ายออกรวม (นำออก)</p>
                            <h3 class="text-warning-emphasis fw-bold mb-0"><?= number_format($total_out_all); ?> <small class="fs-6 text-muted fw-normal">ชิ้น/หน่วย</small></h3>
                            <div class="extra-small text-muted mt-1"><?= ($filter_fy === 'all') ? 'จ่ายออกทั้งหมด' : 'จ่ายออกในปีงบฯ ' . htmlspecialchars($filter_fy); ?></div>
                        </div>
                        <div class="stat-icon bg-warning-subtle text-warning-emphasis rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-hand-holding-box"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-success">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">คงคลังรวม (คงเหลือ)</p>
                            <h3 class="text-success fw-bold mb-0"><?= number_format($total_stock_all); ?> <small class="fs-6 text-muted fw-normal">ชิ้น/หน่วย</small></h3>
                            <div class="extra-small mt-1">
                                <?php if ($out_of_stock_count > 0): ?>
                                    <span class="text-danger fw-bold"><i class="fa-solid fa-circle-exclamation me-1"></i>หมด <?= $out_of_stock_count; ?> รายการ</span>
                                <?php else: ?>
                                    <span class="text-success"><i class="fa-solid fa-check-circle me-1"></i>พร้อมเบิกจ่าย</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="stat-icon bg-success-subtle text-success rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-warehouse"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar สำหรับวัสดุคงเหลือ -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white d-print-none">
            <div class="card-body p-3">
                <form action="" method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="stock">
                    <input type="hidden" name="fy" value="<?= htmlspecialchars($filter_fy); ?>">

                    <div class="col-md-5">
                        <div class="position-relative">
                            <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 16px; transform: translateY(-50%);"></i>
                            <input type="text" name="search" class="form-control rounded-pill bg-light border-0 ps-5" placeholder="ค้นหาชื่อรายการวัสดุ หรือประเภท..." value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <select name="cat" class="form-select rounded-pill bg-light border-0 fw-semibold text-secondary" onchange="this.form.submit()">
                            <option value="all">ดูทุกประเภทวัสดุ (<?= count($categories); ?> ประเภท)</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat); ?>" <?= ($filter_cat === $cat) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="status" class="form-select rounded-pill bg-light border-0 fw-semibold text-secondary" onchange="this.form.submit()">
                            <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>สถานะสต็อกทั้งหมด</option>
                            <option value="available" <?= ($filter_status === 'available') ? 'selected' : ''; ?>>พร้อมจ่าย (> 5)</option>
                            <option value="low" <?= ($filter_status === 'low') ? 'selected' : ''; ?>>สต็อกเหลือน้อย (1-5)</option>
                            <option value="out" <?= ($filter_status === 'out') ? 'selected' : ''; ?>>สินค้าหมด (0)</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-purple rounded-pill px-3 fw-bold w-100 shadow-sm">
                            <i class="fa-solid fa-filter me-1"></i> กรอง
                        </button>
                        <?php if (!empty($search) || $filter_cat !== 'all' || $filter_status !== 'all'): ?>
                            <a href="?tab=stock&fy=<?= htmlspecialchars($filter_fy); ?>" class="btn btn-light rounded-pill px-3 text-secondary border shadow-sm" title="ล้างตัวกรอง">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Category Pills Shortcut Buttons -->
        <div class="d-flex align-items-center gap-2 mb-3 overflow-auto pb-2 d-print-none">
            <span class="text-muted extra-small fw-bold text-uppercase text-nowrap"><i class="fa-solid fa-tags me-1"></i>หมวดหมู่:</span>
            <a href="?tab=stock&fy=<?= htmlspecialchars($filter_fy); ?>&cat=all<?= !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm rounded-pill px-3 py-1 <?= ($filter_cat === 'all') ? 'btn-purple shadow-sm' : 'btn-light border text-muted'; ?> text-nowrap">
                ทั้งหมด (<?= $total_skus; ?>)
            </a>
            <?php foreach($categories as $cat): ?>
                <a href="?tab=stock&fy=<?= htmlspecialchars($filter_fy); ?>&cat=<?= urlencode($cat); ?><?= !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-sm rounded-pill px-3 py-1 <?= ($filter_cat === $cat) ? 'btn-purple shadow-sm' : 'btn-light border text-muted'; ?> text-nowrap">
                    <?= htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ตารางแสดงรายการวัสดุคงเหลือ เข้านำ นำออก คงคลัง -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5 bg-white">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-boxes-packing text-purple me-2"></i>รายงานวัสดุคงเหลือแยกตามประเภทและชื่อรายการ
                    </h5>
                    <small class="text-muted">
                        แสดง วันที่นำเข้า, ยอดนำเข้า (รับเข้า), วันที่นำออกล่าสุด, ยอดนำออก และคงคลัง <?= ($filter_fy === 'all') ? 'สะสมทั้งหมด' : 'ประจำปีงบประมาณ ' . htmlspecialchars($filter_fy); ?>
                    </small>
                </div>
                <div class="d-flex gap-2 align-items-center d-print-none">
                    <?php if (hasRole(['admin', 'งานพัสดุ'])): ?>
                        <a href="manage_items.php" class="btn btn-sm btn-outline-purple rounded-pill px-3 shadow-xs">
                            <i class="fa-solid fa-gear me-1"></i> จัดการคลังวัสดุ
                        </a>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark border px-3 py-2 rounded-pill fw-normal">
                        พบ <?= number_format(count($stock_items)); ?> รายการ
                    </span>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border-top border-light">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th class="ps-4 text-center" width="4%">ลำดับ</th>
                                <th width="15%">ประเภทวัสดุ</th>
                                <th width="25%">ชื่อรายการวัสดุ</th>
                                <th class="text-center" width="10%">
                                    <span class="text-purple fw-bold"><i class="fa-regular fa-calendar-plus me-1"></i>วันที่นำเข้า</span>
                                </th>
                                <th class="text-center bg-info-subtle bg-opacity-25" width="8%">
                                    <span class="text-info-emphasis fw-bold"><i class="fa-solid fa-arrow-down-long me-1"></i>นำเข้า</span>
                                </th>
                                <th class="text-center" width="11%">
                                    <span class="text-warning-emphasis fw-bold"><i class="fa-regular fa-calendar-minus me-1"></i>วันที่นำออก</span>
                                </th>
                                <th class="text-center bg-warning-subtle bg-opacity-25" width="10%">
                                    <span class="text-warning-emphasis fw-bold"><i class="fa-solid fa-arrow-up-long me-1"></i>นำออก</span>
                                </th>
                                <th class="text-center bg-success-subtle bg-opacity-25" width="8%">
                                    <span class="text-success fw-bold"><i class="fa-solid fa-warehouse me-1"></i>คงคลัง</span>
                                </th>
                                <th class="text-center" width="4%">หน่วย</th>
                                <th class="text-center" width="5%">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stock_items)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5">
                                        <i class="fa-regular fa-folder-open fs-1 mb-2 text-secondary opacity-25"></i><br>
                                        <span class="text-muted small fw-bold">ไม่พบข้อมูลรายการวัสดุตามเงื่อนไขที่เลือก</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $idx = 1;
                                foreach ($stock_items as $item): 
                                    $stock = (int)$item['stock_qty'];
                                    $in = (int)$item['calc_in'];
                                    $out = (int)$item['calc_out'];
                                    $issue_count = (int)$item['issue_count'];
                                ?>
                                    <tr>
                                        <!-- ลำดับ -->
                                        <td class="ps-4 text-center text-muted small"><?= $idx++; ?></td>

                                        <!-- ประเภทวัสดุ -->
                                        <td>
                                            <?= getCategoryBadge($item['category']); ?>
                                        </td>

                                        <!-- ชื่อรายการวัสดุ -->
                                        <td>
                                            <div class="fw-bold text-dark small mb-0"><?= htmlspecialchars($item['item_name']); ?></div>
                                            <div class="extra-small text-muted">รหัสวัสดุ: #MAT-<?= str_pad($item['item_id'], 4, '0', STR_PAD_LEFT); ?></div>
                                        </td>

                                        <!-- วันที่นำเข้า -->
                                        <td class="text-center small text-secondary">
                                            <?= !empty($item['received_date']) ? formatThaiDateShort($item['received_date']) : '-'; ?>
                                        </td>

                                        <!-- นำเข้า (รับเข้า) -->
                                        <td class="text-center bg-info-subtle bg-opacity-10">
                                            <span class="fw-bold text-info fs-6"><?= number_format($in); ?></span>
                                        </td>

                                        <!-- วันที่นำออก (ล่าสุด) -->
                                        <td class="text-center small">
                                            <?php if (!empty($item['last_issue_date'])): ?>
                                                <div class="fw-semibold text-dark"><?= formatThaiDateShort($item['last_issue_date']); ?></div>
                                                <div class="extra-small text-muted"><?= date('H:i', strtotime($item['last_issue_date'])); ?> น.</div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- นำออก (จ่ายออก) พร้อมปุ่มเปิดดูรายการที่นำออก -->
                                        <td class="text-center bg-warning-subtle bg-opacity-10">
                                            <div><span class="fw-bold text-warning-emphasis fs-6"><?= number_format($out); ?></span></div>
                                            <?php if ($out > 0): ?>
                                                <button type="button" 
                                                        class="btn btn-xs btn-outline-warning text-dark rounded-pill px-2 py-0 extra-small mt-1 shadow-2xs d-print-none" 
                                                        onclick="viewItemIssueHistory(<?= $item['item_id']; ?>, '<?= htmlspecialchars(addslashes($item['item_name'])); ?>', '<?= htmlspecialchars(addslashes($item['unit_name'] ?? 'ชิ้น')); ?>')" 
                                                        title="คลิกดูประวัติรายการที่นำออกของวัสดุชิ้นนี้">
                                                    <i class="fa-solid fa-list-check me-1 text-warning-emphasis"></i>รายการที่นำออก (<?= $issue_count; ?>)
                                                </button>
                                            <?php endif; ?>
                                        </td>

                                        <!-- คงคลัง (คงเหลือ) -->
                                        <td class="text-center bg-success-subtle bg-opacity-10">
                                            <?php if ($stock <= 0): ?>
                                                <span class="fw-bold text-danger fs-6">0</span>
                                            <?php elseif ($stock <= 5): ?>
                                                <span class="fw-bold text-warning-emphasis fs-6"><?= number_format($stock); ?></span>
                                            <?php else: ?>
                                                <span class="fw-bold text-success fs-6"><?= number_format($stock); ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- หน่วยนับ -->
                                        <td class="text-center text-muted small">
                                            <?= htmlspecialchars($item['unit_name'] ?? 'ชิ้น'); ?>
                                        </td>

                                        <!-- สถานะสต็อก -->
                                        <td class="text-center">
                                            <?php if ($item['is_active'] == 0): ?>
                                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1 extra-small">ปิดใช้งาน</span>
                                            <?php elseif ($stock <= 0): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1 extra-small">หมดสต็อก</span>
                                            <?php elseif ($stock <= 5): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-1 extra-small">เหลือน้อย</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 extra-small">พร้อมจ่าย</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($stock_items)): ?>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="ps-4 text-end">รวมยอดทั้งหมด (<?= count($stock_items); ?> รายการ):</td>
                                    <td class="text-center text-info fs-6"><?= number_format($total_in_all); ?></td>
                                    <td class="text-center">-</td>
                                    <td class="text-center text-warning-emphasis fs-6"><?= number_format($total_out_all); ?></td>
                                    <td class="text-center text-success fs-6"><?= number_format($total_stock_all); ?></td>
                                    <td colspan="2" class="text-muted extra-small text-center">หน่วยนับตามรายการ</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    <!-- ================================================================= -->
    <!-- TAB 2: ประวัติรายการที่นำออกทั้งหมด (Issued Transactions Log) -->
    <!-- ================================================================= -->
    <?php elseif ($tab === 'issued'): ?>

        <!-- KPI Cards สำหรับรายการที่นำออก -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-warning">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">จำนวนครั้งที่นำออก (เบิกจ่าย)</p>
                            <h3 class="text-warning-emphasis fw-bold mb-0"><?= number_format($total_issued_items_count); ?> <small class="fs-6 text-muted fw-normal">รายการคำขอ</small></h3>
                            <div class="extra-small text-muted mt-1"><?= ($filter_fy === 'all') ? 'บันทึกการนำออกทั้งหมด' : 'ในปีงบประมาณ ' . htmlspecialchars($filter_fy); ?></div>
                        </div>
                        <div class="stat-icon bg-warning-subtle text-warning-emphasis rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-list-check"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-xl-4">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-purple">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">จำนวนชิ้นที่นำออกรวม</p>
                            <h3 class="text-purple fw-bold mb-0"><?= number_format($total_issued_qty_all); ?> <small class="fs-6 text-muted fw-normal">ชิ้น/หน่วย</small></h3>
                            <div class="extra-small text-muted mt-1">ยอดรวมพัสดุอุปกรณ์ที่จ่ายจริง</div>
                        </div>
                        <div class="stat-icon bg-purple-subtle text-purple rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-box-open"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-12 col-xl-4">
                <div class="card h-100 border-0 shadow-sm bg-white rounded-4 hover-lift border-start border-4 border-success">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 text-muted fw-bold extra-small text-uppercase">สถานะการจ่ายพัสดุ</p>
                            <h3 class="text-success fw-bold mb-0"><i class="fa-solid fa-check-double me-1"></i> จ่ายสำเร็จ</h3>
                            <div class="extra-small text-muted mt-1">ผ่านการอนุมัติและจ่ายพัสดุเรียบร้อย</div>
                        </div>
                        <div class="stat-icon bg-success-subtle text-success rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; font-size: 1.5rem;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar สำหรับรายการที่นำออก -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white d-print-none">
            <div class="card-body p-3">
                <form action="" method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="issued">
                    <input type="hidden" name="fy" value="<?= htmlspecialchars($filter_fy); ?>">

                    <div class="col-md-6">
                        <div class="position-relative">
                            <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 16px; transform: translateY(-50%);"></i>
                            <input type="text" name="search" class="form-control rounded-pill bg-light border-0 ps-5" placeholder="ค้นหาชื่อวัสดุ, ผู้ขอเบิก, หรือประเภท..." value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>

                    <div class="col-md-4">
                        <select name="cat" class="form-select rounded-pill bg-light border-0 fw-semibold text-secondary" onchange="this.form.submit()">
                            <option value="all">ดูทุกประเภทวัสดุ (<?= count($categories); ?> ประเภท)</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat); ?>" <?= ($filter_cat === $cat) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-purple rounded-pill px-3 fw-bold w-100 shadow-sm">
                            <i class="fa-solid fa-filter me-1"></i> กรอง
                        </button>
                        <?php if (!empty($search) || $filter_cat !== 'all'): ?>
                            <a href="?tab=issued&fy=<?= htmlspecialchars($filter_fy); ?>" class="btn btn-light rounded-pill px-3 text-secondary border shadow-sm" title="ล้างตัวกรอง">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- ตารางประวัติรายการที่นำออกทั้งหมด -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5 bg-white">
            <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-dolly text-warning-emphasis me-2"></i>ประวัติรายการวัสดุที่นำออก (เบิกจ่ายจริง)
                    </h5>
                    <small class="text-muted">
                        แสดงข้อมูลวันที่นำออก, ชื่อรายการที่นำออก, จำนวน, และผู้ขอเบิก <?= ($filter_fy === 'all') ? 'ทุกปีงบประมาณ' : 'ประจำปีงบประมาณ ' . htmlspecialchars($filter_fy); ?>
                    </small>
                </div>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill fw-normal">
                    พบ <?= number_format(count($issued_transactions)); ?> รายการ
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border-top border-light">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th class="ps-4 text-center" width="5%">ลำดับ</th>
                                <th width="12%">วันที่นำออก</th>
                                <th width="10%">เลขที่คำขอ</th>
                                <th width="14%">ประเภทวัสดุ</th>
                                <th width="24%">รายการที่นำออก</th>
                                <th class="text-center bg-warning-subtle bg-opacity-25" width="8%">
                                    <span class="text-warning-emphasis fw-bold">จำนวนที่นำออก</span>
                                </th>
                                <th width="15%">ผู้ขอเบิก</th>
                                <th width="12%">เหตุผลการเบิก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($issued_transactions)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fa-regular fa-folder-open fs-1 mb-2 text-secondary opacity-25"></i><br>
                                        <span class="text-muted small fw-bold">ไม่มีข้อมูลรายการที่นำออกตามเงื่อนไขที่เลือก</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $tx_idx = 1;
                                foreach ($issued_transactions as $tx): 
                                ?>
                                    <tr>
                                        <!-- ลำดับ -->
                                        <td class="ps-4 text-center text-muted small"><?= $tx_idx++; ?></td>

                                        <!-- วันที่นำออก -->
                                        <td>
                                            <div class="small fw-bold text-dark"><?= formatThaiDateShort($tx['issue_date']); ?></div>
                                            <div class="extra-small text-muted"><?= date('H:i', strtotime($tx['issue_date'])); ?> น.</div>
                                        </td>

                                        <!-- เลขที่คำขอ -->
                                        <td>
                                            <a href="view.php?id=<?= $tx['request_id']; ?>" class="fw-bold text-purple text-decoration-none small" title="คลิกดูใบเบิก">
                                                REQ-<?= str_pad($tx['request_id'], 4, '0', STR_PAD_LEFT); ?>
                                                <i class="fa-solid fa-arrow-up-right-from-square extra-small ms-1"></i>
                                            </a>
                                        </td>

                                        <!-- ประเภทวัสดุ -->
                                        <td>
                                            <?= getCategoryBadge($tx['category']); ?>
                                        </td>

                                        <!-- รายการที่นำออก -->
                                        <td>
                                            <div class="fw-bold text-dark small mb-0"><?= htmlspecialchars($tx['item_name']); ?></div>
                                            <div class="extra-small text-muted">รหัสวัสดุ: #MAT-<?= str_pad($tx['item_id'], 4, '0', STR_PAD_LEFT); ?></div>
                                        </td>

                                        <!-- จำนวนที่นำออก -->
                                        <td class="text-center bg-warning-subtle bg-opacity-10">
                                            <span class="fw-bold text-warning-emphasis fs-6"><?= number_format($tx['issued_qty']); ?></span>
                                            <span class="text-muted extra-small ms-1"><?= htmlspecialchars($tx['unit_name']); ?></span>
                                        </td>

                                        <!-- ผู้ขอเบิก -->
                                        <td>
                                            <div class="fw-semibold text-dark small"><?= htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name']); ?></div>
                                            <div class="extra-small text-secondary text-truncate" style="max-width: 160px;"><?= htmlspecialchars($tx['position'] ?? '-'); ?></div>
                                        </td>

                                        <!-- เหตุผลการเบิก -->
                                        <td>
                                            <span class="d-inline-block text-truncate small text-secondary" style="max-width: 180px;" title="<?= htmlspecialchars($tx['request_reason'] ?? '-'); ?>">
                                                <?= htmlspecialchars($tx['request_reason'] ?? '-'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($issued_transactions)): ?>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="5" class="ps-4 text-end">รวมยอดนำออกทั้งหมด (<?= count($issued_transactions); ?> รายการ):</td>
                                    <td class="text-center text-warning-emphasis fs-6"><?= number_format($total_issued_qty_all); ?></td>
                                    <td colspan="2" class="text-muted extra-small">ชิ้น/หน่วย ตามรายการ</td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    <!-- ================================================================= -->
    <!-- TAB 3: ประวัติคำขอเบิกพัสดุ (Requests by Order) -->
    <!-- ================================================================= -->
    <?php else: ?>

        <!-- สถิติ Dashboard ประวัติการเบิก -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white hover-lift rounded-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="mb-1 text-muted fw-bold extra-small text-uppercase">คำขอเบิกทั้งหมด</p>
                                <h3 class="text-dark fw-bold mb-0"><?= number_format($total_requests); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                            </div>
                            <div class="stat-icon bg-purple-subtle text-purple rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;"><i class="fa-solid fa-file-lines"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white hover-lift rounded-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="mb-1 text-muted fw-bold extra-small text-uppercase">รอการดำเนินการ</p>
                                <h3 class="text-dark fw-bold mb-0"><?= number_format($pending_requests); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                            </div>
                            <div class="stat-icon bg-warning-subtle text-warning rounded-4 p-3 d-flex align-items-center justify-content-center" style="color: #d97706 !important; width: 48px; height: 48px;"><i class="fa-solid fa-hourglass-half"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white hover-lift rounded-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="mb-1 text-muted fw-bold extra-small text-uppercase">จ่ายของสำเร็จแล้ว</p>
                                <h3 class="text-dark fw-bold mb-0"><?= number_format($issued_requests); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                            </div>
                            <div class="stat-icon bg-success-subtle text-success rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;"><i class="fa-solid fa-box-open"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 border-0 shadow-sm bg-white hover-lift rounded-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <p class="mb-1 text-muted fw-bold extra-small text-uppercase">ไม่อนุมัติ / ยกเลิก</p>
                                <h3 class="text-dark fw-bold mb-0"><?= number_format($rejected_requests); ?> <small class="fs-6 text-muted fw-normal">รายการ</small></h3>
                            </div>
                            <div class="stat-icon bg-danger-subtle text-danger rounded-4 p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;"><i class="fa-solid fa-ban"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ตารางประวัติคำขอเบิกพัสดุ -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5 bg-white">
            <div class="card-header bg-white py-4 border-bottom-0 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark fs-6">
                    <i class="fa-solid fa-table-list me-2 text-purple"></i> รายการคำขอเบิกพัสดุ (<?= ($filter_fy === 'all') ? 'ทุกปีงบประมาณ' : 'ปีงบฯ ' . htmlspecialchars($filter_fy); ?>)
                </span>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill fw-normal">
                    พบ <?= number_format(count($requests_list)); ?> รายการ
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border-top border-light">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" width="10%">เลขที่</th>
                                <th width="15%">วัน/เวลา</th>
                                <th width="18%">ผู้ขอเบิก</th>
                                <th width="20%">เหตุผลในการเบิก</th>
                                <th class="text-center" width="10%">จำนวน(ชิ้น)</th>
                                <th class="text-center" width="9%">หัวหน้า</th>
                                <th class="text-center" width="9%">พัสดุ</th>
                                <th class="pe-4 text-center" width="9%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests_list)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fa-regular fa-folder-open fs-1 mb-2 text-secondary opacity-25"></i><br>
                                        <span class="text-muted small fw-bold">ไม่มีข้อมูลคำขอเบิกในปีงบประมาณนี้</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests_list as $req): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-dark small">REQ-<?= str_pad($req['request_id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td>
                                        <div class="small fw-bold text-dark"><?= date('d M Y', strtotime($req['request_date'])); ?></div>
                                        <div class="extra-small text-muted"><?= date('H:i', strtotime($req['request_date'])); ?> น.</div>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-dark small"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="d-inline-block text-truncate small text-secondary" style="max-width: 200px;" title="<?= htmlspecialchars($req['request_reason'] ?? '-'); ?>">
                                            <?= htmlspecialchars($req['request_reason'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td class="text-center fw-bold text-purple"><?= $req['item_count']; ?></td>
                                    <td class="text-center"><?= getBadge($req['head_status'], 'head'); ?></td>
                                    <td class="text-center">
                                        <?php 
                                            if ($req['head_status'] == 'pending') echo '<span class="badge bg-light text-muted border px-3 py-1 rounded-pill fw-normal shadow-sm">-</span>';
                                            elseif ($req['head_status'] == 'rejected') echo '<span class="badge bg-light text-muted border px-3 py-1 rounded-pill fw-normal shadow-sm">ยกเลิก</span>';
                                            else echo getBadge($req['supply_status'], 'supply'); 
                                        ?>
                                    </td>
                                    <td class="pe-4 text-center">
                                        <a href="view.php?id=<?= $req['request_id']; ?>" class="btn-ghost-view" title="ดูรายละเอียด">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>

</main>

<!-- Modal แสดงประวัติรายการที่นำออกของวัสดุรายชิ้น (Item Issue History Modal) -->
<div class="modal fade" id="itemIssueModal" tabindex="-1" aria-labelledby="itemIssueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-purple text-white border-0 py-3 rounded-top-4">
                <div>
                    <h5 class="modal-title fw-bold" id="itemIssueModalLabel">
                        <i class="fa-solid fa-list-check me-2 text-warning"></i>ประวัติรายการที่นำออก
                    </h5>
                    <div class="extra-small text-white-50" id="modalItemSubtitle">รหัสวัสดุ: -</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body p-4 bg-light">
                <div class="card border-0 shadow-xs rounded-4 bg-white mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <span class="extra-small text-muted d-block fw-bold text-uppercase">ชื่อรายการวัสดุ</span>
                                <span class="fw-bold text-dark small" id="modalItemName">-</span>
                            </div>
                            <div class="col-md-3">
                                <span class="extra-small text-muted d-block fw-bold text-uppercase">วันที่นำเข้าคลัง</span>
                                <span class="small text-secondary fw-semibold" id="modalItemReceivedDate">-</span>
                            </div>
                            <div class="col-md-3">
                                <span class="extra-small text-muted d-block fw-bold text-uppercase">คงเหลือในคลัง</span>
                                <span class="fw-bold text-success fs-6" id="modalItemStock">-</span>
                            </div>
                            <div class="col-md-2 text-end">
                                <span class="badge bg-purple-subtle text-purple border border-purple-subtle rounded-pill px-3 py-1" id="modalItemCategory">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-xs rounded-4 bg-white overflow-hidden">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark small"><i class="fa-solid fa-clock-rotate-left text-warning-emphasis me-2"></i>รายการคำขอเบิกที่นำออกไป</span>
                        <span class="badge bg-light text-muted border px-3 py-1 rounded-pill" id="modalTotalIssuedSummary">-</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 380px;">
                            <table class="table table-hover align-middle mb-0 border-top border-light" id="modalIssueTable">
                                <thead class="table-light text-secondary small">
                                    <tr>
                                        <th class="ps-4 text-center" width="5%">#</th>
                                        <th width="20%">วันที่นำออก</th>
                                        <th width="15%">เลขที่คำขอ</th>
                                        <th width="25%">ผู้ขอเบิก</th>
                                        <th width="23%">เหตุผลการเบิก</th>
                                        <th class="text-center pe-4" width="12%">จำนวนนำออก</th>
                                    </tr>
                                </thead>
                                <tbody id="modalIssueBody">
                                    <tr><td colspan="6" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>กำลังโหลดข้อมูล...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white border-0 py-3 rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold shadow-xs" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * ฟังก์ชันเปิด Modal ดูประวัติรายการที่นำออกของวัสดุชิ้นที่เลือก
 */
function viewItemIssueHistory(itemId, itemName, unitName) {
    const modalEl = document.getElementById('itemIssueModal');
    const modal = new bootstrap.Modal(modalEl);
    
    document.getElementById('modalItemName').innerText = itemName;
    document.getElementById('modalItemSubtitle').innerText = 'รหัสวัสดุ: #MAT-' + String(itemId).padStart(4, '0');
    document.getElementById('modalIssueBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>กำลังโหลดข้อมูลรายการที่นำออก...</td></tr>';
    
    modal.show();

    // ดึงข้อมูลผ่าน AJAX
    const fyParam = '<?= htmlspecialchars($filter_fy); ?>';
    fetch(`report.php?action=get_item_issued_history&item_id=${itemId}&fy=${fyParam}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const item = data.item || {};
                const list = data.history || [];

                document.getElementById('modalItemReceivedDate').innerText = item.received_date ? formatThaiDate(item.received_date) : '-';
                document.getElementById('modalItemStock').innerText = (item.stock_qty || 0) + ' ' + (item.unit_name || unitName);
                document.getElementById('modalItemCategory').innerText = item.category || '-';

                let sumQty = 0;
                let html = '';

                if (list.length === 0) {
                    html = '<tr><td colspan="6" class="text-center py-4 text-muted">ไม่พบประวัติรายการที่นำออกสำหรับรายการนี้</td></tr>';
                } else {
                    list.forEach((row, i) => {
                        sumQty += parseInt(row.issued_qty || 0);
                        const issueDateText = formatThaiDateTime(row.issue_date);
                        const requesterName = (row.first_name || '') + ' ' + (row.last_name || '');
                        const reqCode = 'REQ-' + String(row.request_id).padStart(4, '0');
                        
                        html += `
                            <tr>
                                <td class="ps-4 text-center text-muted small">${i + 1}</td>
                                <td>
                                    <div class="fw-bold small text-dark">${issueDateText.date}</div>
                                    <div class="extra-small text-muted">${issueDateText.time} น.</div>
                                </td>
                                <td>
                                    <a href="view.php?id=${row.request_id}" target="_blank" class="fw-bold text-purple small text-decoration-none" title="เปิดดูใบเบิก">
                                        ${reqCode} <i class="fa-solid fa-up-right-from-square extra-small ms-1"></i>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold small text-dark">${requesterName}</div>
                                    <div class="extra-small text-muted text-truncate" style="max-width: 180px;">${row.position || '-'}</div>
                                </td>
                                <td>
                                    <span class="small text-secondary d-inline-block text-truncate" style="max-width: 190px;" title="${row.request_reason || '-'}">
                                        ${row.request_reason || '-'}
                                    </span>
                                </td>
                                <td class="text-center pe-4">
                                    <span class="badge bg-warning-subtle text-warning-emphasis fs-6 rounded-pill px-3 py-1">
                                        ${Number(row.issued_qty).toLocaleString()} ${item.unit_name || unitName}
                                    </span>
                                </td>
                            </tr>
                        `;
                    });
                }

                document.getElementById('modalIssueBody').innerHTML = html;
                document.getElementById('modalTotalIssuedSummary').innerHTML = `รวมนำออก: <strong class="text-warning-emphasis">${sumQty.toLocaleString()} ${item.unit_name || unitName}</strong> (${list.length} ครั้ง)`;
            } else {
                document.getElementById('modalIssueBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
            }
        })
        .catch(err => {
            document.getElementById('modalIssueBody').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์</td></tr>';
        });
}

function formatThaiDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return `${d.getDate()} ${months[d.getMonth() + 1]} ${d.getFullYear() + 543}`;
}

function formatThaiDateTime(dateStr) {
    if (!dateStr) return { date: '-', time: '' };
    const parts = dateStr.split(' ');
    const dParts = parts[0].split('-');
    const tParts = (parts[1] || '00:00').split(':');
    
    if (dParts.length === 3) {
        const year = parseInt(dParts[0]) + 543;
        const monthNum = parseInt(dParts[1]);
        const day = parseInt(dParts[2]);
        const months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        const dateFormatted = `${day} ${months[monthNum]} ${year}`;
        const timeFormatted = `${tParts[0]}:${tParts[1]}`;
        return { date: dateFormatted, time: timeFormatted };
    }
    return { date: dateStr, time: '' };
}
</script>

<?php require_once '../../includes/footer.php'; ?>
