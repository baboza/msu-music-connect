<?php
// ไฟล์สำหรับสร้าง CSV Template ให้ดาวน์โหลด
require_once '../../auth/session_check.php';

$type = $_GET['type'] ?? '';
$filename = "template_" . str_replace('cv_', '', $type) . ".csv";

// ตั้งค่า Header ให้บราวเซอร์รู้ว่านี่คือไฟล์ดาวน์โหลด CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// แทรก BOM (Byte Order Mark) ลงไปตอนต้นไฟล์ 
// เพื่อบังคับให้ Microsoft Excel เปิดอ่านเป็น UTF-8 ทันที (ป้องกันภาษาต่างดาว)
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// เช็คว่าผู้ใช้กดโหลดเทมเพลตของตารางไหน
if ($type == 'cv_courses') {
    fputcsv($output, ['รหัสวิชา (ไม่ระบุก็ได้)', 'ชื่อรายวิชา (บังคับ)', 'ระดับการศึกษา (เช่น ปริญญาตรี)']);
    fputcsv($output, ['MUS101', 'ประวัติศาสตร์ดนตรีตะวันตก', 'ปริญญาตรี']); // แถวตัวอย่าง
} 
elseif ($type == 'cv_advisor') {
    fputcsv($output, ['ชื่อ-สกุล นิสิต (บังคับ)', 'ชื่อเรื่องวิทยานิพนธ์', 'ปี พ.ศ.', 'ประเภท (ที่ปรึกษาหลัก/ร่วม)']);
    fputcsv($output, ['นายเรียนดี ขยันยิ่ง', 'การพัฒนาเทคนิคเปียโน', '2566', 'ที่ปรึกษาหลัก']); // แถวตัวอย่าง
} 
elseif ($type == 'cv_committee') {
    fputcsv($output, ['ชื่อ-สกุล นิสิต (บังคับ)', 'ชื่อเรื่องวิทยานิพนธ์', 'ปี พ.ศ.']);
    fputcsv($output, ['นางสาวเก่ง มากมาย', 'การประพันธ์เพลงร่วมสมัย', '2567']); // แถวตัวอย่าง
} 
elseif ($type == 'cv_awards') {
    fputcsv($output, ['ชื่อรางวัล (บังคับ)', 'หน่วยงานที่มอบ', 'ปี พ.ศ. ที่ได้รับ']);
    fputcsv($output, ['รางวัลอาจารย์ดีเด่น', 'มหาวิทยาลัยมหาสารคาม', '2565']); // แถวตัวอย่าง
}

fclose($output);
exit();
?>