<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : 'all';
$filter_dept = isset($_GET['dept_id']) ? $_GET['dept_id'] : 'all';
$filter_year = isset($_GET['year']) ? $_GET['year'] : 'all';
$filter_source = isset($_GET['source']) ? $_GET['source'] : 'all';
$filter_db = isset($_GET['db_source']) ? $_GET['db_source'] : 'all';

$conditions = [];
$params = [];

if ($filter_user !== 'all') { $conditions[] = "r.user_id = :uid"; $params[':uid'] = $filter_user; }
if ($filter_dept !== 'all') { $conditions[] = "u.dept_id = :dept"; $params[':dept'] = $filter_dept; }
if ($filter_year !== 'all') { $conditions[] = "r.publish_year = :yr"; $params[':yr'] = $filter_year; }
if ($filter_source !== 'all') { $conditions[] = "r.publisher = :src"; $params[':src'] = $filter_source; }
if ($filter_db !== 'all') {
    if ($filter_db === 'other') {
        $conditions[] = "(r.db_source IS NULL OR r.db_source = '' OR r.db_source NOT IN ('Scopus', 'ERIC', 'TCI'))";
    } else {
        $conditions[] = "r.db_source = :dbsrc";
        $params[':dbsrc'] = $filter_db;
    }
}

$where_sql = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";

$sql_list = "SELECT r.publish_year, r.title, r.authors, r.publisher, r.url_link, r.citations, r.db_source, u.prefix_name, u.first_name, u.last_name, d.dept_name 
             FROM cv_research r 
             JOIN users u ON r.user_id = u.user_id 
             LEFT JOIN departments d ON u.dept_id = d.dept_id
             $where_sql 
             ORDER BY r.publish_year DESC, r.created_at DESC";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params);
$researches = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=research_export_".date('Ymd_His').".xls");
header("Pragma: no-cache");
header("Expires: 0");

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" /></head><body>';
echo '<table border="1">';
echo '<tr>
        <th style="background-color: #f2f2f2;">ปีที่พิมพ์ (ค.ศ.)</th>
        <th style="background-color: #f2f2f2;">ชื่องานวิจัย / บทความ</th>
        <th style="background-color: #f2f2f2;">ผู้แต่ง (Authors)</th>
        <th style="background-color: #f2f2f2;">ผู้บันทึก (Owner)</th>
        <th style="background-color: #f2f2f2;">ภาควิชา / สังกัด</th>
        <th style="background-color: #f2f2f2;">แหล่งตีพิมพ์ / วารสาร</th>
        <th style="background-color: #f2f2f2;">ฐานข้อมูล (Database)</th>
        <th style="background-color: #f2f2f2;">Citations</th>
        <th style="background-color: #f2f2f2;">ลิงก์ / DOI</th>
      </tr>';

foreach($researches as $r) {
    $authors = !empty($r['authors']) ? $r['authors'] : $r['prefix_name'] . $r['first_name'] . ' ' . $r['last_name'];
    $owner = $r['prefix_name'] . $r['first_name'] . ' ' . $r['last_name'];
    echo '<tr>';
    echo '<td>' . htmlspecialchars($r['publish_year'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($r['title'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($authors) . '</td>';
    echo '<td>' . htmlspecialchars($owner) . '</td>';
    echo '<td>' . htmlspecialchars($r['dept_name'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($r['publisher'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($r['db_source'] ?? '-') . '</td>';
    echo '<td>' . htmlspecialchars($r['citations'] ?? '0') . '</td>';
    echo '<td>' . htmlspecialchars($r['url_link'] ?? '-') . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</body></html>';
?>
