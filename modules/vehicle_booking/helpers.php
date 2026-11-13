<?php
// helpers.php - Vehicle Booking Module Helpers

if (!function_exists('formatThaiDT')) {
    function formatThaiDT($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '-';
        $ts = strtotime($datetime);
        if (!$ts) return '-';
        $months = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
            7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
        ];
        $d = date('j', $ts);
        $m = $months[(int)date('n', $ts)] ?? '';
        $y = (int)date('Y', $ts) + 543;
        $h = date('H:i', $ts);
        return "{$d} {$m} {$y} {$h} น.";
    }
}

if (!function_exists('formatThaiDateOnly')) {
    function formatThaiDateOnly($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00') return '-';
        $ts = strtotime($datetime);
        if (!$ts) return '-';
        $months = [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
            7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
        ];
        $d = date('j', $ts);
        $m = $months[(int)date('n', $ts)] ?? '';
        $y = (int)date('Y', $ts) + 543;
        return "{$d} {$m} {$y}";
    }
}

if (!function_exists('getVehicleBookingStatusBadge')) {
    function getVehicleBookingStatusBadge($status) {
        switch ($status) {
            case 'pending_head':
                return [
                    'label' => 'รอหัวหน้าสำนักงานพิจารณา',
                    'class' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                    'icon'  => 'fa-solid fa-hourglass-half',
                    'step'  => 1
                ];
            case 'pending_deputy':
                return [
                    'label' => 'รอรองคณบดีฝ่ายบริหารพิจารณา',
                    'class' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                    'icon'  => 'fa-solid fa-user-clock',
                    'step'  => 2
                ];
            case 'approved':
                return [
                    'label' => 'อนุมัติการใช้รถแล้ว',
                    'class' => 'bg-success-subtle text-success border border-success-subtle',
                    'icon'  => 'fa-solid fa-circle-check',
                    'step'  => 3
                ];
            case 'completed':
                return [
                    'label' => 'เดินทางเรียบร้อยแล้ว',
                    'class' => 'bg-primary-subtle text-primary border border-primary-subtle',
                    'icon'  => 'fa-solid fa-flag-checkered',
                    'step'  => 4
                ];
            case 'rejected':
                return [
                    'label' => 'ไม่อนุมัติคำขอ',
                    'class' => 'bg-danger-subtle text-danger border border-danger-subtle',
                    'icon'  => 'fa-solid fa-circle-xmark',
                    'step'  => -1
                ];
            case 'cancelled':
                return [
                    'label' => 'ยกเลิกคำขอแล้ว',
                    'class' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                    'icon'  => 'fa-solid fa-ban',
                    'step'  => 0
                ];
            default:
                return [
                    'label' => $status,
                    'class' => 'bg-light text-dark border',
                    'icon'  => 'fa-solid fa-circle-question',
                    'step'  => 0
                ];
        }
    }
}

if (!function_exists('checkVehicleConflict')) {
    function checkVehicleConflict($pdo, $vehicle_id, $start, $end, $exclude_id = null) {
        $sql = "
            SELECT booking_id, booking_code, title, start_datetime, end_datetime, status 
            FROM vehicle_bookings 
            WHERE vehicle_id = ? 
              AND status IN ('pending_head', 'pending_deputy', 'approved')
              AND NOT (end_datetime <= ? OR start_datetime >= ?)
        ";
        $params = [$vehicle_id, $start, $end];
        if ($exclude_id) {
            $sql .= " AND booking_id != ?";
            $params[] = $exclude_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('generateBookingCode')) {
    function generateBookingCode($pdo) {
        $y = (int)date('Y') + 543;
        $prefix = "VB-" . $y . "-";
        $stmt = $pdo->query("SELECT booking_code FROM vehicle_bookings WHERE booking_code LIKE '{$prefix}%' ORDER BY booking_id DESC LIMIT 1");
        $last = $stmt->fetchColumn();
        if ($last) {
            $num = (int)substr($last, -4) + 1;
        } else {
            $num = 1;
        }
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
