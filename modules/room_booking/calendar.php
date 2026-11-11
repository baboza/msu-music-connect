<?php
// เปิด Error ชั่วคราว
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

// 1. จัดการเรื่อง วันที่/เดือน/ปี
// 💡 แก้ไข: ใช้ date('n') แทน date('m') เพื่อไม่ให้มีเลข 0 นำหน้า
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// หาวันแรกของเดือน และจำนวนวันในเดือนนั้น
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$number_of_days = date('t', $first_day_of_month);
$date_components = getdate($first_day_of_month);
$month_name = $date_components['month'];
$day_of_week = $date_components['wday']; // 0 (Sun) - 6 (Sat)

// ชื่อเดือนภาษาไทย (คีย์เป็นตัวเลข 1-12)
$thai_months = [
    1 => "มกราคม", 2 => "กุมภาพันธ์", 3 => "มีนาคม", 4 => "เมษายน", 
    5 => "พฤษภาคม", 6 => "มิถุนายน", 7 => "กรกฎาคม", 8 => "สิงหาคม", 
    9 => "กันยายน", 10 => "ตุลาคม", 11 => "พฤศจิกายน", 12 => "ธันวาคม"
];

// 2. ดึงข้อมูลการจองเฉพาะเดือนที่เลือก (เฉพาะที่อนุมัติแล้ว หรือรออนุมัติ)
// เติม 0 ให้เดือนสำหรับการ query database (เช่น "2026-04-01")
$month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
$start_date = "$year-$month_padded-01";
$end_date = "$year-$month_padded-$number_of_days";

$sql = "SELECT b.*, r.room_code, r.room_name 
        FROM room_bookings b
        JOIN rooms r ON b.room_id = r.room_id
        WHERE b.booking_date BETWEEN ? AND ? 
        AND b.status IN ('approved', 'pending')
        ORDER BY b.start_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date, $end_date]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// จัดกลุ่มข้อมูลการจองตามวันที่ เพื่อให้ดึงไปแสดงในปฏิทินง่ายขึ้น
$events_by_date = [];
foreach ($bookings as $bk) {
    $events_by_date[$bk['booking_date']][] = $bk;
}

// 3. ปุ่มย้อนกลับ/ถัดไป
$prev_month = ($month == 1) ? 12 : $month - 1;
$prev_year = ($month == 1) ? $year - 1 : $year;
$next_month = ($month == 12) ? 1 : $month + 1;
$next_year = ($month == 12) ? $year + 1 : $year;

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 mb-5" style="background-color: #f8f9fc; min-height: 100vh;">
    
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-4 pb-3 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-purple mb-1"><i class="fa-regular fa-calendar-days me-2"></i>ผังการใช้ห้องรายเดือน</h1>
            <p class="text-muted small">แสดงภาพรวมการจองห้องเรียนและห้องซ้อมทั้งหมดในวิทยาลัย</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm">
                <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn btn-white border-0"><i class="fa-solid fa-chevron-left"></i></a>
                <div class="btn btn-white border-0 fw-bold px-4" style="min-width: 200px;">
                    <?php echo $thai_months[$month] . " " . ($year + 543); ?>
                </div>
                <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn btn-white border-0"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <a href="index.php" class="btn btn-purple rounded-pill px-4 shadow-sm fw-bold">
                <i class="fa-solid fa-list me-1"></i> ดูแบบรายการ
            </a>
        </div>
    </div>

    <div class="d-flex gap-4 mb-3 px-2">
        <div class="small text-muted"><span class="d-inline-block rounded-circle bg-success me-1" style="width:10px; height:10px;"></span> อนุมัติแล้ว</div>
        <div class="small text-muted"><span class="d-inline-block rounded-circle bg-warning me-1" style="width:10px; height:10px;"></span> รอพิจารณา</div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="calendar-grid">
                <?php 
                $days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
                foreach($days as $d) echo "<div class='calendar-header'>$d</div>";
                ?>

                <?php for($i=0; $i<$day_of_week; $i++) echo "<div class='calendar-day empty'></div>"; ?>

                <?php 
                for($current_day = 1; $current_day <= $number_of_days; $current_day++): 
                    // 💡 แก้ไข: ใช้ str_pad กับเดือนเพื่อสร้างวันที่ให้ตรงกับ format ในฐานข้อมูล (YYYY-MM-DD)
                    $current_date = sprintf("%04d-%02d-%02d", $year, $month, $current_day);
                    $is_today = ($current_date == date('Y-m-d')) ? 'is-today' : '';
                ?>
                    <div class="calendar-day <?php echo $is_today; ?>">
                        <div class="day-number"><?php echo $current_day; ?></div>
                        
                        <div class="event-container">
                            <?php if(isset($events_by_date[$current_date])): ?>
                                <?php foreach($events_by_date[$current_date] as $event): ?>
                                    <a href="view_booking.php?id=<?php echo $event['booking_id']; ?>" 
                                       class="event-item <?php echo $event['status']; ?>"
                                       title="<?php echo htmlspecialchars($event['room_name']); ?>">
                                        <strong><?php echo date('H:i', strtotime($event['start_time'])); ?></strong> 
                                        <?php echo $event['room_code']; ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</main>

<style>
    :root { --msu-purple: #39048f; --msu-gold: #b0914f; }
    .text-purple { color: var(--msu-purple); }
    .btn-purple { background-color: var(--msu-purple); color: white; border: none; }
    .btn-white { background-color: white; color: var(--msu-purple); border: 1px solid #eee; }
    
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background-color: #eee;
        gap: 1px;
    }
    .calendar-header {
        background-color: #fff;
        padding: 15px;
        text-align: center;
        font-weight: bold;
        color: var(--msu-purple);
        font-size: 0.9rem;
    }
    .calendar-day {
        background-color: #fff;
        min-height: 140px;
        padding: 10px;
        transition: background 0.2s;
    }
    .calendar-day.empty { background-color: #fcfcfc; }
    .calendar-day:hover { background-color: #f8f9ff; }
    .calendar-day.is-today { background-color: #fff9e6; }
    .calendar-day.is-today .day-number { 
        background: var(--msu-gold); 
        color: white; 
        width: 25px; height: 25px; 
        display: flex; align-items: center; justify-content: center; 
        border-radius: 50%;
    }
    
    .day-number { font-weight: bold; color: #aaa; margin-bottom: 8px; font-size: 1rem; }
    
    .event-container { display: flex; flex-direction: column; gap: 4px; }
    .event-item {
        font-size: 0.75rem;
        padding: 4px 8px;
        border-radius: 6px;
        text-decoration: none;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-left: 3px solid transparent;
    }
    .event-item.approved { background-color: #d1e7dd; color: #0f5132; border-left-color: #198754; }
    .event-item.pending { background-color: #fff3cd; color: #664d03; border-left-color: #ffc107; }
    .event-item:hover { opacity: 0.8; transform: scale(1.02); transition: 0.2s; }
</style>

<?php require_once '../../includes/footer.php'; ?>