<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../auth/session_check.php';
require_once '../../config/db.php';

$meeting_id = $_GET['id'] ?? '';
if (empty($meeting_id)) {
    die("ไม่พบรหัสการประชุม");
}

// ดึงข้อมูลรายงานการประชุม
try {
    $stmt = $pdo->prepare("SELECT title, meeting_no, minutes_content FROM meetings WHERE meeting_id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        die("ไม่พบข้อมูลการประชุมในระบบ");
    }

    // ถ้ายังไม่มีการร่างรายงาน ให้แจ้งเตือน
    if (empty($meeting['minutes_content'])) {
        echo "<script>alert('ยังไม่มีการจัดทำรายงานการประชุมฉบับนี้ กรุณาให้งานธุรการร่างรายงานก่อนครับ'); window.close();</script>";
        exit();
    }

    if (isset($_GET['export']) && $_GET['export'] == 'doc') {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $base_uri = dirname(dirname(dirname($_SERVER['PHP_SELF'])));
        if ($base_uri == '\\' || $base_uri == '/') $base_uri = '';
        $base_url = $protocol . $host . str_replace('\\', '/', $base_uri);

        $content = $meeting['minutes_content'];
        $content = str_replace('../../uploads/', $base_url . '/uploads/', $content);

        header("Content-Type: application/vnd.ms-word");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("content-disposition: attachment;filename=minutes_" . urlencode($meeting['meeting_no']) . ".doc");
        echo "<html xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo "<head><meta charset='utf-8'></head>";
        echo "<body>";
        echo $content;
        echo "</body>";
        echo "</html>";
        exit();
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานการประชุม - <?php echo htmlspecialchars($meeting['title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-color: #525659; /* สีพื้นหลังแบบโปรแกรมอ่าน PDF */
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: var(--bg-color);
            margin: 0;
            padding: 0;
            font-size: 15pt; /* ปรับขนาดฟอนต์ให้พอดีขึ้น (Google Sarabun 15pt ใกล้เคียง TH Sarabun 16pt) */
            line-height: 1.5;
            color: #000;
        }
        
        /* แถบเมนูด้านบน (ซ่อนตอนปริ้น) */
        .top-bar {
            background-color: #333;
            color: white;
            padding: 15px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .top-bar button {
            font-family: 'Sarabun', sans-serif;
            font-size: 14pt;
            padding: 8px 25px;
            margin: 0 5px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .btn-print { background-color: #4b0082; color: white; }
        .btn-print:hover { background-color: #39048f; }
        .btn-close-win { background-color: #f8f9fa; color: #333; }
        .btn-close-win:hover { background-color: #e2e6ea; }

        /* หน้ากระดาษ A4 */
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 20mm 25mm; /* ขอบซ้ายกว้างกว่าเล็กน้อยเผื่อเข้าแฟ้ม */
            margin: 20px auto;
            background: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.5);
            box-sizing: border-box;
        }

        /* ปรับแต่งเนื้อหาภายใน A4 ที่มาจาก Editor */
        .a4-page h1, .a4-page h2, .a4-page h3, .a4-page h4, .a4-page h5, .a4-page h6 {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 10px;
        }
        .a4-page h1 { font-size: 20pt; }
        .a4-page h2 { font-size: 18pt; }
        .a4-page h3 { font-size: 17pt; }
        .a4-page h4 { font-size: 16pt; }
        .a4-page h5 { font-size: 15pt; }
        .a4-page h6 { font-size: 14pt; }
        .a4-page p {
            margin-top: 0;
            margin-bottom: 10px;
            text-align: justify; /* จัดหน้ากระดาษแบบกระจายชิดขอบ */
        }
        .a4-page table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .a4-page table, .a4-page th, .a4-page td {
            border: 1px solid black;
            padding: 5px 10px;
        }

        /* ตั้งค่าการพิมพ์ (Ctrl+P) */
        @media print {
            body { background-color: white; }
            .top-bar { display: none !important; } /* ซ่อนปุ่ม */
            .a4-page {
                margin: 0;
                padding: 20mm 20mm 20mm 25mm; /* ใช้ขอบกระดาษจาก Browser */
                box-shadow: none;
                width: 100%;
                min-height: auto;
                page-break-after: always;
            }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

    <div class="top-bar">
        <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> พิมพ์ / บันทึกเป็น PDF</button>
        <button class="btn-close-win" onclick="window.close()"><i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่างนี้</button>
        <div style="font-size: 12pt; margin-top: 5px; color: #ccc;">แนะนำให้ตั้งค่า Paper size: A4, Margins: Default หรือ None</div>
    </div>

    <div class="a4-page">
        <?php 
            // แสดงผล HTML ที่ถูกบันทึกมาจาก TinyMCE
            echo $meeting['minutes_content']; 
        ?>
    </div>

</body>
</html>