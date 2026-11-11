<?php
require_once 'auth_check.php';

function getOccupiedRooms($pdo) {
    date_default_timezone_set('Asia/Bangkok');
    $today = date('Y-m-d');
    $current_time = date('H:i:s');
    
    $stmt = $pdo->prepare("
        SELECT room_id 
        FROM practice_bookings 
        WHERE booking_date = ? 
        AND status = 'active' 
        AND start_time <= ? 
        AND end_time >= ?
    ");
    $stmt->execute([$today, $current_time, $current_time]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$stmt = $pdo->query("SELECT * FROM practice_rooms ORDER BY room_id ASC");
$all_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$occupied_rooms = getOccupiedRooms($pdo);

$lRooms = array_filter($all_rooms, function($r) { return strpos($r['room_id'], 'PL') !== false; });
$rRooms = array_filter($all_rooms, function($r) { return strpos($r['room_id'], 'PR') !== false; });

function renderRoomRow($rooms, $isOdd, $occupied_rooms) {
    $filtered = array_filter($rooms, function($r) use ($isOdd) {
        $num = (int)substr($r['room_id'], -2);
        return ($num % 2 !== 0) === $isOdd;
    });
    usort($filtered, function($a, $b) { return strcmp($a['room_id'], $b['room_id']); });
    
    $html = '';
    foreach($filtered as $r) {
        $isOccupied = in_array($r['room_id'], $occupied_rooms);
        $isPiano = ($r['room_type'] === 'piano');
        $roomIdShort = str_replace('MUA ', '', $r['room_id']);
        $isActive = (isset($r['is_active']) && $r['is_active']);
        
        if (!$isActive) {
            $html .= "<div class='room-box mb-2'>
                        <div class='card text-center border-secondary h-100 shadow-sm' style='background:#f8f9fa; opacity:0.6; cursor:not-allowed;' title='{$r['disabled_reason']}'>
                            <div class='card-body p-1 d-flex flex-column justify-content-center'>
                                <h6 class='mb-0 fw-bold text-decoration-line-through text-secondary' style='font-size:0.75rem;'>{$roomIdShort}</h6>
                                <small class='text-danger d-block' style='font-size:0.6rem;'>งดใช้</small>
                            </div>
                        </div>
                      </div>";
        } else {
            $statusText = $isOccupied ? 'ไม่ว่าง' : 'ว่าง';
            $statusColor = $isOccupied ? 'text-danger' : 'text-success';
            $bgColor = $isOccupied ? 'bg-danger-subtle' : 'bg-success-subtle';
            $pianoBorder = $isPiano ? 'border-primary border-2' : 'border-0';
            
            // View-only box but selectable
            $html .= "<div class='room-box mb-2'>
                        <div class='card text-center h-100 shadow-sm {$bgColor} {$pianoBorder}' style='cursor:pointer;' onclick='document.querySelector(\"select[name=room_id]\").value=\"{$r['room_id']}\"; document.querySelector(\"select[name=room_id]\").dispatchEvent(new Event(\"change\"));'>
                            <div class='card-body p-1 d-flex flex-column justify-content-center'>
                                <h6 class='mb-0 fw-bold text-dark' style='font-size:0.75rem;'>{$roomIdShort}</h6>
                                <small class='{$statusColor} d-block fw-bold' style='font-size:0.6rem;'>{$statusText}</small>
                            </div>
                        </div>
                      </div>";
        }
    }
    return $html;
}
?>

<style>
.room-box { width: 12.5%; padding: 0 4px; float: left; }
@media (max-width: 768px) { .room-box { width: 25%; } }
.map-container { overflow: hidden; }
</style>

<div class="map-container mb-3">
    <!-- Zone L -->
    <div class="mb-3">
        <h6 class="fw-bold border-bottom pb-1 mb-2 text-primary small">Zone L (ฝั่งตู้โค้ก)</h6>
        <div class="clearfix">
            <?php echo renderRoomRow($lRooms, true, $occupied_rooms); ?>
        </div>
        <div class="clearfix mt-1">
            <?php echo renderRoomRow($lRooms, false, $occupied_rooms); ?>
        </div>
    </div>
    
    <!-- Zone R -->
    <div class="mb-2">
        <h6 class="fw-bold border-bottom pb-1 mb-2 text-primary small">Zone R (ฝั่งพระพิฆเนศ)</h6>
        <div class="clearfix">
            <?php echo renderRoomRow($rRooms, true, $occupied_rooms); ?>
        </div>
        <div class="clearfix mt-1">
            <?php echo renderRoomRow($rRooms, false, $occupied_rooms); ?>
        </div>
    </div>
</div>
