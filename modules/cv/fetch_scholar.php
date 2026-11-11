<?php
require_once '../../auth/session_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$scholar_id = $_GET['scholar_id'] ?? '';

// รับค่าตัวแปรสำหรับแบ่งหน้า (Pagination) จาก Javascript
$cstart = isset($_GET['cstart']) ? (int)$_GET['cstart'] : 0;
$pagesize = isset($_GET['pagesize']) ? (int)$_GET['pagesize'] : 20;

$api_key = "10388e08aedc8ceb12bf8348b5b76d76de0aa25f70d132fec43fe7202345f905"; 

if (empty($scholar_id)) {
    echo json_encode(['error' => 'ไม่พบ Scholar ID']);
    exit;
}

// 1. ดึงข้อมูลจาก SerpApi (เพิ่มพารามิเตอร์ start และ num สำหรับแบ่งหน้า)
$url = "https://serpapi.com/search.json?engine=google_scholar_author&author_id={$scholar_id}&hl=th&start={$cstart}&num={$pagesize}&api_key={$api_key}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

// หาก API ส่งกลับมาว่าไม่มีข้อมูล articles
if (!isset($data['articles']) || empty($data['articles'])) {
    if ($cstart == 0) {
        // ถ้าเป็นการดึงหน้าแรกแล้วไม่เจอเลย แปลว่า ID ผิด หรือไม่มีผลงานจริงๆ
        echo json_encode(['error' => 'ไม่พบข้อมูลงานวิจัย หรือ ID ไม่ถูกต้อง']);
    } else {
        // ถ้าเป็นหน้าถัดๆ ไป แปลว่าดึงข้อมูลมาครบหมดแล้ว ให้ส่ง Array ว่างกลับไปเพื่อหยุดลูป Javascript
        echo json_encode([]);
    }
    exit;
}

// 2. ดึงชื่อผลงานที่มีอยู่แล้วในฐานข้อมูลเรามาเทียบ (กันซ้ำ)
$stmt = $pdo->prepare("SELECT title FROM cv_research WHERE user_id = ?");
$stmt->execute([$user_id]);
$existing_titles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 3. กรองและเตรียมส่งข้อมูลกลับไปแสดงที่หน้าเว็บ
$results = [];
foreach ($data['articles'] as $art) {
    $title = $art['title'];
    $is_existing = in_array($title, $existing_titles);
    
    $results[] = [
        'title' => $title,
        'authors' => $art['authors'] ?? '', // [อัปเดต] ดึงชื่อผู้แต่งจาก API ของ Scholar มาด้วย
        'publisher' => $art['publication'] ?? 'ไม่ระบุแหล่งตีพิมพ์',
        'year' => $art['year'] ?? '',
        'link' => $art['link'] ?? '',
        'is_existing' => $is_existing // ส่งไปบอกหน้าบ้านว่ามีแล้วหรือยัง
    ];
}

echo json_encode($results);