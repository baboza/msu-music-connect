<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ตรวจสอบและดึงไฟล์ PHPMailer
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/**
 * กำหนดค่าสำหรับการส่งอีเมลผ่าน Gmail SMTP
 * นำรูปแบบมาจากระบบ music-cert โดยเว้น password ไว้สำหรับระบุบัญชีจริง
 */
function getSmtpConfig() {
    return [
        'host'       => 'smtp.gmail.com',
        'port'       => 465,
        'secure'     => PHPMailer::ENCRYPTION_SMTPS, // SSL
        'username'   => 'taranya1999@gmail.com', // <--- อีเมลผู้ส่ง (เช่น ...@msu.ac.th)
        'password'   => 'pybzqpwnftolfxmw', // <--- Google App Password 16 หลัก
        'from_name'  => 'ระบบ e-Service วิทยาลัยดุริยางคศิลป์ มมส.'
    ];
}

/**
 * ส่งอีเมลแจ้งเตือนไปยังอาจารย์ผู้สอนเมื่อนิสิตยื่นคำร้องใหม่
 * 
 * @param int $request_id รหัสคำร้องในตาราง course_requests
 * @param PDO $pdo อ็อบเจกต์เชื่อมต่อฐานข้อมูล
 * @return bool ผลการส่งอีเมล (true = สำเร็จ, false = ล้มเหลวหรือยังไม่ตั้งค่ารหัสผ่าน)
 */
function sendCourseRequestNotification($request_id, $pdo) {
    if (!$pdo || $request_id <= 0) {
        return false;
    }

    try {
        // 1. ดึงข้อมูลคำร้อง ข้อมูลนิสิต และข้อมูลอาจารย์ผู้สอน
        $stmt = $pdo->prepare("
            SELECT r.*,
                   s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
                   s.major AS std_major, s.year_level AS std_year,
                   inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_lname,
                   inst.email AS inst_email
            FROM course_requests r
            LEFT JOIN students s ON r.student_id = s.student_id
            LEFT JOIN users inst ON r.instructor_id = inst.user_id
            WHERE r.request_id = ?
        ");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            error_log("[Mailer] Request #{$request_id} not found.");
            return false;
        }

        // 2. ตรวจสอบอีเมลปลายทางของอาจารย์
        $to_email = trim($req['inst_email'] ?? '');
        if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            error_log("[Mailer] Instructor email for user_id {$req['instructor_id']} is empty or invalid ({$to_email}).");
            return false;
        }

        // 3. ตรวจสอบการตั้งค่า SMTP
        $cfg = getSmtpConfig();
        if (empty($cfg['password']) || $cfg['username'] === 'your_email@msu.ac.th') {
            // ยังไม่ได้ระบุรหัสผ่าน SMTP ให้บันทึก Log และข้ามอย่างปลอดภัย ไม่ให้หน้าเว็บ Error
            error_log("[Mailer] SMTP password is not configured yet. Skipping email to {$to_email}.");
            return false;
        }

        // 4. เตรียมข้อมูลเนื้อหา
        $req_type = $req['request_type'];
        $req_id_formatted = "REQ-" . str_pad($request_id, 4, '0', STR_PAD_LEFT);
        $std_name = trim(($req['std_prefix'] ?? '') . ($req['std_fname'] ?? '') . ' ' . ($req['std_lname'] ?? ''));
        $inst_name = trim(($req['inst_prefix'] ?? '') . ($req['inst_fname'] ?? '') . ' ' . ($req['inst_lname'] ?? ''));
        $course_code = htmlspecialchars($req['course_code']);
        $course_name = htmlspecialchars($req['course_name']);
        $semester = htmlspecialchars($req['semester']);
        $section = htmlspecialchars($req['section'] ?? '');
        $reason = nl2br(htmlspecialchars($req['reason']));

        // คำนวณลิงก์ปลายทางใน e-Office สำหรับอาจารย์
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_system = $protocol . $host . '/music-e-office';

        if ($req_type === 'open_course') {
            $type_title_th = "คำร้องขอเปิดรายวิชาใหม่";
            $subject = "[e-Service] มีคำร้องขอเปิดรายวิชาใหม่: {$course_code} {$course_name} (นิสิต: {$std_name})";
            $view_url = "{$base_system}/modules/open_course/view.php?id={$request_id}";
            $color = "#5a2e8f"; // ม่วงเข้ม
        } else {
            $type_title_th = "คำร้องขอสำรองที่นั่งใหม่";
            $subject = "[e-Service] มีคำร้องขอสำรองที่นั่งใหม่: {$course_code} {$course_name}" . ($section ? " Sec {$section}" : "") . " (นิสิต: {$std_name})";
            $view_url = "{$base_system}/modules/reserve_seat/view.php?id={$request_id}";
            $color = "#d97706"; // ส้มทอง
        }

        // 5. ประกอบ HTML Template อีเมล (สไตล์เดียวกับ music-cert)
        $body = "
        <div style='font-family: Sarabun, -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 620px; margin: 0 auto; border: 1px solid #e2e8f0; border-top: 8px solid {$color}; border-radius: 12px; background-color: #ffffff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);'>
            <div style='padding: 32px 28px;'>
                <h2 style='color: {$color}; margin-top: 0; margin-bottom: 8px; font-size: 20px;'>{$type_title_th}</h2>
                <div style='color: #64748b; font-size: 13px; margin-bottom: 20px;'>รหัสคำร้อง: <strong>#{$req_id_formatted}</strong> | วันที่ยื่น: " . date('d/m/Y H:i') . " น.</div>
                
                <p style='color: #334155; font-size: 15px; line-height: 1.6; margin-top: 0;'>
                    เรียน <strong>อาจารย์{$inst_name}</strong>,<br>
                    มีนิสิตได้ยื่นคำร้องผ่านระบบ e-Service และระบุท่านเป็นอาจารย์ผู้สอน/ผู้ประสานงานรายวิชา โดยมีรายละเอียดคำร้องดังนี้:
                </p>

                <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; margin: 20px 0;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; width: 140px; vertical-align: top;'><strong>นิสิตผู้ยื่นคำร้อง:</strong></td>
                            <td style='padding: 6px 0; color: #1e293b; font-weight: bold;'>{$std_name} (รหัส {$req['student_id']})</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; vertical-align: top;'><strong>สาขาวิชา / ชั้นปี:</strong></td>
                            <td style='padding: 6px 0; color: #1e293b;'>{$req['std_major']} (ชั้นปี {$req['std_year']})</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; vertical-align: top;'><strong>รายวิชา:</strong></td>
                            <td style='padding: 6px 0; color: {$color}; font-weight: bold;'>{$course_code} {$course_name}</td>
                        </tr>";

        if (!empty($section)) {
            $body .= "
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; vertical-align: top;'><strong>กลุ่มเรียน (Sec):</strong></td>
                            <td style='padding: 6px 0; color: #b45309; font-weight: bold;'>กลุ่ม {$section}</td>
                        </tr>";
        }

        $body .= "
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; vertical-align: top;'><strong>ภาคการศึกษา:</strong></td>
                            <td style='padding: 6px 0; color: #1e293b;'>ภาคเรียน {$semester}</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; color: #64748b; vertical-align: top;'><strong>เหตุผลความจำเป็น:</strong></td>
                            <td style='padding: 6px 0; color: #334155; line-height: 1.5;'>{$reason}</td>
                        </tr>
                    </table>
                </div>

                <div style='text-align: center; margin: 32px 0;'>
                    <a href='{$view_url}' target='_blank' style='display: inline-block; background-color: {$color}; color: #ffffff; padding: 14px 34px; font-size: 15px; font-weight: bold; text-decoration: none; border-radius: 50px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);'>
                        👉 เข้าสู่ระบบเพื่อพิจารณาคำร้อง (e-Office)
                    </a>
                </div>

                <p style='color: #64748b; font-size: 13px; text-align: center; margin-bottom: 0;'>
                    หากคลิกปุ่มไม่ได้ สามารถคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์:<br>
                    <a href='{$view_url}' style='color: {$color}; word-break: break-all;'>{$view_url}</a>
                </p>

                <div style='margin-top: 35px; padding-top: 18px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;'>
                    วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม (College of Music, Mahasarakham University)<br>
                    * อีเมลฉบับนี้ส่งโดยระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้
                </div>
            </div>
        </div>";

        // 6. ส่งอีเมลผ่าน PHPMailer
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['secure'];
        $mail->Port       = $cfg['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['username'], $cfg['from_name']);
        $mail->addAddress($to_email, $inst_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("[Mailer Error] Failed to send email for request #{$request_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * ส่งอีเมลแจ้งเตือนการเปลี่ยนแปลงสถานะคำร้อง (อนุมัติส่งต่อ / ไม่อนุมัติ / เสร็จสิ้น)
 * 
 * @param int $request_id รหัสคำร้อง
 * @param string $final_status สถานะใหม่ ('pending_advisor', 'pending_head', 'pending_deputy', 'pending_academic', 'approved', 'rejected')
 * @param string $comment ความเห็นที่บันทึก
 * @param string $approver_title ตำแหน่ง/บทบาทผู้พิจารณา
 * @param PDO $pdo
 * @return bool
 */
function sendCourseRequestNextStepNotification($request_id, $final_status, $comment, $approver_title, $pdo) {
    if (!$pdo || $request_id <= 0) return false;

    try {
        $stmt = $pdo->prepare("
            SELECT r.*,
                   s.prefix_name AS std_prefix, s.first_name AS std_fname, s.last_name AS std_lname,
                   s.email AS std_email, s.major AS std_major,
                   inst.prefix_name AS inst_prefix, inst.first_name AS inst_fname, inst.last_name AS inst_last,
                   adv.prefix_name AS adv_prefix, adv.first_name AS adv_fname, adv.last_name AS adv_last,
                   adv.email AS adv_email
            FROM course_requests r
            LEFT JOIN students s ON r.student_id = s.student_id
            LEFT JOIN users inst ON r.instructor_id = inst.user_id
            LEFT JOIN users adv ON r.advisor_id = adv.user_id
            WHERE r.request_id = ?
        ");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) return false;

        $cfg = getSmtpConfig();
        if (empty($cfg['password']) || $cfg['username'] === 'your_email@msu.ac.th') {
            return false;
        }

        $req_type_text = ($req['request_type'] === 'open_course') ? 'ขอเปิดรายวิชา' : 'ขอสำรองที่นั่ง';
        $course_code = htmlspecialchars($req['course_code']);
        $course_name = htmlspecialchars($req['course_name']);
        $std_name = trim(($req['std_prefix'] ?? '') . ($req['std_fname'] ?? '') . ' ' . ($req['std_lname'] ?? ''));
        $adv_name = trim(($req['adv_prefix'] ?? '') . ($req['adv_fname'] ?? '') . ' ' . ($req['adv_last'] ?? ''));
        $req_code = "REQ-" . str_pad($request_id, 4, '0', STR_PAD_LEFT);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_system = $protocol . $host . '/music-e-office';

        $to_email = '';
        $to_name = '';
        $subject = '';
        $title_color = '#39048f';
        $headline = '';
        $desc = '';

        if ($final_status === 'pending_advisor') {
            $to_email = trim($req['adv_email'] ?? '');
            $to_name = $adv_name;
            if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

            $subject = "[e-Service] มีคำร้อง{$req_type_text}รอท่านพิจารณา: {$course_code} {$course_name} (นิสิต: {$std_name})";
            $title_color = '#39048f';
            $headline = "คำร้อง{$req_type_text} ผ่านการพิจารณาจากอาจารย์ผู้สอนแล้ว";
            $view_url = "{$base_system}/requests_approve.php?id={$request_id}";
            $desc = "เรียน อาจารย์{$adv_name},<br>คำร้องของนิสิต <strong>{$std_name}</strong> ได้รับการอนุมัติจากอาจารย์ผู้สอนแล้ว และส่งต่อมายังท่านในฐานะอาจารย์ที่ปรึกษาเพื่อพิจารณาต่อไป";
        } elseif ($final_status === 'approved') {
            $to_email = trim($req['std_email'] ?? '');
            $to_name = $std_name;
            if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

            $subject = "[e-Service] คำร้อง{$req_type_text}ของคุณได้รับการ \"อนุมัติเสร็จสมบูรณ์\" ({$course_code} {$course_name})";
            $title_color = '#198754';
            $headline = "คำร้องของคุณได้รับการอนุมัติเสร็จสมบูรณ์แล้ว";
            $view_url = "{$protocol}{$host}/e-service/request_open_course.php";
            $desc = "เรียน คุณ{$std_name},<br>คำร้อง{$req_type_text} รหัสรายวิชา <strong>{$course_code} {$course_name}</strong> ได้รับการอนุมัติครบทุกขั้นตอนเรียบร้อยแล้ว";
        } elseif ($final_status === 'rejected') {
            $to_email = trim($req['std_email'] ?? '');
            $to_name = $std_name;
            if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;

            $subject = "[e-Service] แจ้งผลคำร้อง{$req_type_text}: ไม่ได้รับการอนุมัติ ({$course_code} {$course_name})";
            $title_color = '#dc3545';
            $headline = "คำร้องของคุณไม่ได้รับการอนุมัติ";
            $view_url = "{$protocol}{$host}/e-service/request_open_course.php";
            $desc = "เรียน คุณ{$std_name},<br>คำร้อง{$req_type_text} รหัสรายวิชา <strong>{$course_code} {$course_name}</strong> ไม่ผ่านการอนุมัติในขั้นตอน <strong>{$approver_title}</strong>";
        } else {
            return true;
        }

        $comment_html = !empty($comment) ? "<div style='margin-top:12px; padding:12px; background:#f1f5f9; border-radius:6px; font-size:14px; color:#334155;'><strong>ความเห็นจากผู้พิจารณา ({$approver_title}):</strong><br>" . nl2br(htmlspecialchars($comment)) . "</div>" : "";

        $body = "
        <div style='font-family: Sarabun, -apple-system, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-top: 6px solid {$title_color}; border-radius: 10px; background: #ffffff; padding: 28px;'>
            <h2 style='color: {$title_color}; margin-top:0; font-size: 19px;'>{$headline}</h2>
            <div style='color: #64748b; font-size: 13px; margin-bottom: 16px;'>รหัสคำร้อง: <strong>#{$req_code}</strong> | ปรับปรุงเมื่อ: " . date('d/m/Y H:i') . " น.</div>
            <p style='color: #334155; font-size: 15px; line-height: 1.6;'>{$desc}</p>
            {$comment_html}
            <div style='text-align: center; margin: 28px 0;'>
                <a href='{$view_url}' target='_blank' style='display:inline-block; background:{$title_color}; color:#ffffff; padding:12px 28px; text-decoration:none; border-radius:50px; font-weight:bold; font-size:14px;'>
                    ดูรายละเอียดคำร้อง
                </a>
            </div>
            <div style='margin-top:24px; padding-top:14px; border-top:1px solid #e2e8f0; font-size:12px; color:#94a3b8; text-align:center;'>
                วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม
            </div>
        </div>";

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['secure'];
        $mail->Port       = $cfg['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['username'], $cfg['from_name']);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[Mailer Error] Next step notify failed for #{$request_id}: " . $e->getMessage());
        return false;
    }
}
