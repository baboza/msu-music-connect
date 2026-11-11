<?php
/**
 * Helper Functions for Mobile Approvals & Action Inbox
 * อิงตรรกะและสิทธิ์การแสดงผลคำร้องตาม music-e-office/requests_list.php
 */

if (!function_exists('getMobileApprovalPermissions')) {
    function getMobileApprovalPermissions($pdo, $user_id, $roles, $dept_id = null) {
        if (!is_array($roles)) {
            $roles = !empty($roles) ? [$roles] : [];
        }

        if ($dept_id === null) {
            try {
                $stmt = $pdo->prepare("SELECT dept_id FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $dept_id = (int)$stmt->fetchColumn();
            } catch (Exception $e) {
                $dept_id = 0;
            }
        }

        // --- Flags สิทธิ์ตามระบบหลัก (requests_list.php) ---
        $is_admin = in_array('admin', $roles) ? 1 : 0;
        $is_dean = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $roles)) > 0) ? 1 : 0;

        // คำร้องนิสิต (Course Requests)
        $is_course_head = (count(array_intersect(['หัวหน้าภาควิชาดนตรีศึกษา', 'หัวหน้าภาควิชาดุริยางคศาสตร์', 'ประธานหลักสูตร', 'หัวหน้าภาควิชา'], $roles)) > 0) ? 1 : 0;
        $is_course_deputy = in_array('รองคณบดีฝ่ายวิชาการ', $roles) ? 1 : 0;
        $is_course_academic = (count(array_intersect(['งานบริการการศึกษา', 'งานวิชาการ'], $roles)) > 0) ? 1 : 0;

        // ใบลา (Leave Requests)
        $is_leave_step1 = (count(array_intersect([
            'งานบุคคล', 'นักวิชาการพัสดุ', 'นักวิชาการศึกษา', 'บุคลากร', 
            'นักวิชาการเงินและบัญชี', 'เจ้าหน้าที่บริหารงานทั่วไป', 
            'นักวิเคราะห์นโยบายและแผน', 'นักวิชาการโสตทัศนศึกษา'
        ], $roles)) > 0) ? 1 : 0;

        $my_head_dept_ids = [];
        if (in_array('หัวหน้าภาควิชาดนตรีศึกษา', $roles)) $my_head_dept_ids[] = 12;
        if (in_array('หัวหน้าภาควิชาดุริยางคศาสตร์', $roles)) $my_head_dept_ids[] = 13;
        foreach ($roles as $r) {
            if (mb_strpos($r, 'หัวหน้าภาค') !== false || mb_strpos($r, 'ประธานหลักสูตร') !== false) {
                if ($dept_id > 0 && !in_array($dept_id, $my_head_dept_ids)) {
                    $my_head_dept_ids[] = $dept_id;
                }
            }
        }

        $is_leave_step2_staff = (count(array_intersect(['หัวหน้าสำนักงาน', 'หัวหน้าสำนักงานเลขานุการ'], $roles)) > 0) ? 1 : 0;
        $is_leave_step3 = in_array('รองคณบดีฝ่ายบริหาร', $roles) ? 1 : 0;
        $is_leave_step4 = (count(array_intersect(['คณบดี', 'รักษาการคณบดี'], $roles)) > 0) ? 1 : 0;

        // เบิกพัสดุ (Supplies Requests)
        $is_supply_head = in_array('หัวหน้าสำนักงาน', $roles) ? 1 : 0;
        $is_supply_issuer = in_array('งานพัสดุ', $roles) ? 1 : 0;

        // จองห้อง / ซ่อมบำรุง
        $is_building_staff = (in_array('งานอาคารสถานที่', $roles) || in_array('โสตทัศนูปกรณ์', $roles)) ? 1 : 0;

        // กยศ. / จิตอาสา
        $is_klof_staff = (count(array_intersect(['admin', 'งานพัฒนานิสิต', 'รองคณบดีฝ่ายพัฒนานิสิต'], $roles)) > 0) ? 1 : 0;

        $u_quoted = $pdo->quote($user_id);

        // 1. เงื่อนไขใบลา
        $leave_where = [];
        if ($is_admin) {
            $leave_where[] = "1=1";
        } else {
            if ($is_leave_step1) $leave_where[] = "l.current_step = 1";
            if ($is_leave_step2_staff) $leave_where[] = "(l.current_step = 2 AND (u.dept_id = 11 OR l.user_id NOT IN (SELECT user_id FROM user_roles WHERE role_id = 1)))";
            if (!empty($my_head_dept_ids)) $leave_where[] = "(l.current_step = 2 AND u.dept_id IN (" . implode(',', $my_head_dept_ids) . "))";
            if ($is_leave_step3) $leave_where[] = "l.current_step = 3";
            if ($is_leave_step4) $leave_where[] = "(l.current_step = 4 AND (l.final_signer_id IS NULL OR l.final_signer_id = '' OR l.final_signer_id = {$u_quoted}))";
        }

        // 2. เงื่อนไขคำร้องวิชาการนิสิต
        $course_where = [];
        if ($is_admin) {
            $course_where[] = "cr.status IN ('pending_instructor', 'pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic')";
        } else {
            $course_where[] = "(cr.instructor_id = {$u_quoted} AND cr.status = 'pending_instructor')";
            $course_where[] = "(cr.advisor_id = {$u_quoted} AND cr.status = 'pending_advisor')";
            if ($is_course_head) $course_where[] = "cr.status = 'pending_head'";
            if ($is_course_deputy) $course_where[] = "cr.status = 'pending_deputy'";
            if ($is_course_academic) $course_where[] = "cr.status = 'pending_academic'";
        }

        // 3. เงื่อนไขเบิกพัสดุ
        $supply_where = [];
        if ($is_admin) {
            $supply_where[] = "(sr.head_status = 'pending' OR (sr.head_status = 'approved' AND sr.supply_status = 'pending'))";
        } else {
            $supply_where[] = "(sr.head_status = 'pending' AND sr.head_approver_id = {$u_quoted})";
            if ($is_supply_head) $supply_where[] = "sr.head_status = 'pending'";
            if ($is_supply_issuer) $supply_where[] = "(sr.head_status = 'approved' AND sr.supply_status = 'pending')";
        }

        return [
            'leave_where' => $leave_where ? implode(' OR ', $leave_where) : '1=0',
            'course_where' => $course_where ? implode(' OR ', $course_where) : '1=0',
            'supply_where' => $supply_where ? implode(' OR ', $supply_where) : '1=0',
            'room_allowed' => ($is_admin || $is_building_staff),
            'repair_allowed' => ($is_admin || $is_building_staff),
            'klof_allowed' => ($is_admin || $is_klof_staff),
        ];
    }
}

if (!function_exists('getMobileApprovalCounts')) {
    function getMobileApprovalCounts($pdo, $user_id, $roles, $dept_id = null) {
        $p = getMobileApprovalPermissions($pdo, $user_id, $roles, $dept_id);
        
        $cnt_leave = 0;
        $cnt_course = 0;
        $cnt_supply = 0;
        $cnt_room = 0;
        $cnt_repair = 0;
        $cnt_klof = 0;

        try {
            if ($p['leave_where'] !== '1=0') {
                $cnt_leave = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests l JOIN users u ON l.user_id = u.user_id WHERE l.status = 'pending' AND ({$p['leave_where']})")->fetchColumn();
            }
            if ($p['course_where'] !== '1=0') {
                $cnt_course = (int)$pdo->query("SELECT COUNT(*) FROM course_requests cr WHERE ({$p['course_where']})")->fetchColumn();
            }
            if ($p['supply_where'] !== '1=0') {
                $cnt_supply = (int)$pdo->query("SELECT COUNT(*) FROM supplies_requests sr WHERE ({$p['supply_where']})")->fetchColumn();
            }
            if ($p['room_allowed']) {
                $cnt_room = (int)$pdo->query("SELECT COUNT(*) FROM room_bookings WHERE status = 'pending'")->fetchColumn();
            }
            if ($p['repair_allowed']) {
                $cnt_repair = (int)$pdo->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'pending'")->fetchColumn();
            }
            if ($p['klof_allowed']) {
                $cnt_klof = (int)$pdo->query("SELECT COUNT(*) FROM student_klof_logs WHERE status = 'pending'")->fetchColumn();
            }
        } catch (Exception $e) {}

        $total = $cnt_leave + $cnt_course + $cnt_supply + $cnt_room + $cnt_repair + $cnt_klof;

        return [
            'leave' => $cnt_leave,
            'academic' => $cnt_course,
            'supply' => $cnt_supply,
            'room' => $cnt_room,
            'repair' => $cnt_repair,
            'klof' => $cnt_klof,
            'total' => $total
        ];
    }
}

if (!function_exists('getMobileApprovalData')) {
    function getMobileApprovalData($pdo, $user_id, $roles, $dept_id = null) {
        $p = getMobileApprovalPermissions($pdo, $user_id, $roles, $dept_id);
        $counts = getMobileApprovalCounts($pdo, $user_id, $roles, $dept_id);

        $leaves = [];
        $courses = [];
        $supplies = [];
        $rooms = [];
        $repairs = [];
        $klof = [];

        try {
            if ($counts['leave'] > 0) {
                $stmt = $pdo->query("SELECT l.*, t.type_name, u.first_name, u.last_name, u.prefix_name 
                                     FROM leave_requests l 
                                     JOIN leave_types t ON l.type_id = t.type_id 
                                     JOIN users u ON l.user_id = u.user_id 
                                     WHERE l.status = 'pending' AND ({$p['leave_where']})
                                     ORDER BY l.created_at DESC");
                $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($counts['academic'] > 0) {
                $stmt = $pdo->query("SELECT cr.*, 
                                            COALESCE(CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), CONCAT(u.prefix_name, u.first_name, ' ', u.last_name), 'ไม่พบข้อมูลนิสิต') AS student_name,
                                            s.student_id AS student_code
                                     FROM course_requests cr 
                                     LEFT JOIN students s ON cr.student_id = s.student_id 
                                     LEFT JOIN users u ON cr.student_id = u.user_id 
                                     WHERE ({$p['course_where']})
                                     ORDER BY cr.created_at DESC");
                $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($counts['supply'] > 0) {
                $stmt = $pdo->query("SELECT sr.*, u.first_name, u.last_name, u.prefix_name 
                                     FROM supplies_requests sr 
                                     JOIN users u ON sr.user_id = u.user_id 
                                     WHERE ({$p['supply_where']})
                                     ORDER BY sr.request_date DESC");
                $supplies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($counts['room'] > 0) {
                $stmt = $pdo->query("SELECT b.*, r.room_name, 
                                            COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS requester
                                     FROM room_bookings b 
                                     JOIN rooms r ON b.room_id = r.room_id 
                                     LEFT JOIN users u ON b.user_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci 
                                     LEFT JOIN students s ON b.user_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci 
                                     WHERE b.status = 'pending' 
                                     ORDER BY b.created_at DESC");
                $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($counts['repair'] > 0) {
                $stmt = $pdo->query("SELECT rr.*, r.room_name, 
                                            COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT(s.prefix_name, s.first_name, ' ', s.last_name), 'ไม่พบข้อมูล') AS requester
                                     FROM repair_requests rr 
                                     LEFT JOIN rooms r ON rr.room_id = r.room_id 
                                     LEFT JOIN users u ON rr.reporter_id COLLATE utf8mb4_unicode_ci = u.user_id COLLATE utf8mb4_unicode_ci 
                                     LEFT JOIN students s ON rr.reporter_id COLLATE utf8mb4_unicode_ci = s.student_id COLLATE utf8mb4_unicode_ci 
                                     WHERE rr.status = 'pending' 
                                     ORDER BY rr.created_at DESC");
                $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($counts['klof'] > 0) {
                $stmt = $pdo->query("SELECT l.*, s.first_name, s.last_name 
                                     FROM student_klof_logs l 
                                     JOIN students s ON l.student_id = s.student_id 
                                     WHERE l.status = 'pending' 
                                     ORDER BY l.created_at DESC");
                $klof = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}

        return [
            'leaves' => $leaves,
            'courses' => $courses,
            'supplies' => $supplies,
            'rooms' => $rooms,
            'repairs' => $repairs,
            'klof' => $klof,
            'counts' => $counts,
            'total' => $counts['total']
        ];
    }
}
