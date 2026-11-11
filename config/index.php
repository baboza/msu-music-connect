<?php
/**
 * Security Shield: ป้องกันการแอบดูรายชื่อไฟล์ในโฟลเดอร์ (Directory Browsing)
 * จะดีดผู้ใช้งานกลับไปยังหน้าหลักของระบบทันที
 */
header("Location: ../../index.php"); 
exit();
?>