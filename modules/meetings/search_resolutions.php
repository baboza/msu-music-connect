<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$keyword = $_GET['q'] ?? '';
$results = [];

if (!empty($keyword)) {
    try {
        // ค้นหาคำในมติที่ประชุม หรือ ชื่อวาระ หรือ รายละเอียดวาระ
        $stmt = $pdo->prepare("
            SELECT a.agenda_id, a.agenda_number, a.agenda_title, a.agenda_detail, a.resolution, 
                   m.meeting_id, m.meeting_no, m.title, m.meeting_date 
            FROM meeting_agendas a
            JOIN meetings m ON a.meeting_id = m.meeting_id
            WHERE (a.resolution LIKE ? OR a.agenda_title LIKE ? OR a.agenda_detail LIKE ?)
              AND m.status != 'draft'
            ORDER BY m.meeting_date DESC
        ");
        $searchTerm = "%{$keyword}%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาดในการค้นหา: " . $e->getMessage();
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// ฟังก์ชันไฮไลต์คำค้นหา
function highlightKeyword($text, $keyword) {
    if (empty($keyword)) return htmlspecialchars($text);
    if (empty($text)) return '';
    $escaped_keyword = preg_quote($keyword, '/');
    $highlighted = preg_replace("/($escaped_keyword)/i", "<mark class='bg-warning text-dark px-1 fw-bold rounded'>$1</mark>", htmlspecialchars($text));
    return nl2br($highlighted);
}

function thaiDateShort($date) {
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    return date("j", strtotime($date)) . " " . $months[date("n", strtotime($date))] . " " . (date("Y", strtotime($date)) + 543);
}
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 text-purple fw-bold mb-1"><i class="fa-solid fa-magnifying-glass me-2"></i>ค้นหามติการประชุมย้อนหลัง</h1>
            <p class="text-muted small mb-0">สืบค้นประวัติการอนุมัติหรือมติที่ประชุมจากวาระต่างๆ</p>
        </div>
        <div class="btn-toolbar mt-3 mt-md-0">
            <a href="index.php" class="btn btn-light border rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-arrow-left me-2"></i> กลับหน้าแรก
            </a>
        </div>
    </div>

    <div class="row justify-content-center mb-5">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4" style="background: linear-gradient(135deg, #4b0082, #673ab7);">
                <div class="card-body p-4 p-md-5 text-center">
                    <h4 class="text-white fw-bold mb-4">ค้นหาข้อมูลการประชุม</h4>
                    <form method="GET" action="search_resolutions.php" class="d-flex bg-white rounded-pill p-2 shadow">
                        <span class="input-group-text bg-white border-0 text-muted ps-3"><i class="fa-solid fa-search"></i></span>
                        <input type="text" name="q" class="form-control border-0 shadow-none fs-5" placeholder="ระบุคำค้นหา เช่น อนุมัติงบประมาณ, จัดซื้อ..." value="<?php echo htmlspecialchars($keyword); ?>" required>
                        <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">ค้นหา</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($keyword)): ?>
        <div class="mb-3 fw-bold text-dark">
            <i class="fa-solid fa-poll-h text-purple me-2"></i> ผลการค้นหาสำหรับ <span class="text-primary">"<?php echo htmlspecialchars($keyword); ?>"</span> พบ <?php echo count($results); ?> รายการ
        </div>

        <?php if (empty($results)): ?>
            <div class="card border-0 shadow-sm bg-light rounded-4">
                <div class="card-body text-center py-5 text-muted">
                    <i class="fa-solid fa-folder-open fs-1 mb-3 opacity-25"></i>
                    <h5 class="fw-bold">ไม่พบข้อมูลที่ตรงกับคำค้นหา</h5>
                    <p class="small">ลองใช้คำที่สั้นลง หรือใช้คำที่มีความหมายใกล้เคียง</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($results as $res): ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 agenda-card hover-lift">
                            <div class="card-body p-4">
                                <div class="row">
                                    <div class="col-md-3 border-end-md pe-md-4 mb-3 mb-md-0">
                                        <div class="small text-muted fw-bold text-uppercase mb-1">ข้อมูลการประชุม</div>
                                        <div class="text-purple fw-bold text-truncate" title="<?php echo htmlspecialchars($res['title']); ?>"><?php echo htmlspecialchars($res['title']); ?></div>
                                        <div class="text-dark small mt-1">ครั้งที่ <?php echo htmlspecialchars($res['meeting_no']); ?></div>
                                        <div class="text-muted extra-small mt-1"><i class="fa-regular fa-calendar me-1"></i> <?php echo thaiDateShort($res['meeting_date']); ?></div>
                                        
                                        <a href="meeting_room.php?id=<?php echo $res['meeting_id']; ?>#agenda_<?php echo $res['agenda_id']; ?>" class="btn btn-sm btn-outline-purple rounded-pill w-100 mt-3 fw-bold">
                                            <i class="fa-solid fa-eye me-1"></i> ดูวาระเต็ม
                                        </a>
                                    </div>
                                    <div class="col-md-9 ps-md-4">
                                        <div class="d-flex align-items-start mb-3">
                                            <div class="badge bg-purple px-2 py-1 me-2 shadow-sm rounded">วาระ <?php echo htmlspecialchars($res['agenda_number']); ?></div>
                                            <h6 class="fw-bold text-dark mb-0 mt-1" style="line-height: 1.4;">
                                                <?php echo highlightKeyword($res['agenda_title'], $keyword); ?>
                                            </h6>
                                        </div>
                                        
                                        <?php if (!empty($res['agenda_detail'])): ?>
                                            <div class="text-muted small mb-3 text-truncate-2">
                                                <strong>รายละเอียด: </strong>
                                                <?php echo highlightKeyword(strip_tags($res['agenda_detail']), $keyword); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($res['resolution'])): ?>
                                            <div class="p-3 bg-success-subtle border border-success border-opacity-25 rounded text-dark small" style="border-left: 4px solid #198754 !important;">
                                                <div class="fw-bold text-success mb-1"><i class="fa-solid fa-gavel me-1"></i> มติที่ประชุม:</div>
                                                <div style="line-height: 1.6;">
                                                    <?php echo highlightKeyword($res['resolution'], $keyword); ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="p-2 bg-light border border-warning border-dashed rounded text-muted text-center extra-small">
                                                ยังไม่มีการบันทึกมติ
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<style>
    .text-purple { color: #4b0082; }
    .bg-purple { background-color: #4b0082; color: white; }
    .btn-outline-purple { color: #4b0082; border-color: #4b0082; }
    .btn-outline-purple:hover { background-color: #4b0082; color: white; }
    .extra-small { font-size: 0.75rem; }
    .border-dashed { border-style: dashed !important; border-width: 1px !important; }
    
    .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
    
    .text-truncate-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    @media (min-width: 768px) {
        .border-end-md { border-right: 1px solid #dee2e6; }
    }
</style>

<?php require_once '../../includes/footer.php'; ?>
