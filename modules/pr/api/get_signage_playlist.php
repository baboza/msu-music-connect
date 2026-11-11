<?php
/**
 * API: Get Signage Playlist for Screen 1 (Auto-Loop Display)
 * Returns active, approved signage media with calculated checksum hash for live background sync
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../../config/db.php';

try {
    $today = date('Y-m-d');

    // 1. ดึงรายการป้ายที่ได้รับอนุมัติ และยังอยู่ในช่วงเวลาแสดงผล
    $stmt = $pdo->query("
        SELECT item_id, title, media_type, media_url, duration_seconds, enable_sound, sort_order, target_screen
        FROM pr_signage_items
        WHERE status = 'approved'
          AND start_date <= CURDATE() 
          AND end_date >= CURDATE()
        ORDER BY sort_order ASC, item_id ASC
    ");
    $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ฟังก์ชันแปลงลิงก์ Canva หรือ YouTube ให้อยู่ในรูป Embed URL ที่พร้อมเล่นบนจอ LED
    function formatMediaEmbed($type, $url, $enableSound = 0) {
        $url = trim($url);
        if ($type === 'youtube') {
            // ดึง YouTube Video ID
            $videoId = '';
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches)) {
                $videoId = $matches[1];
            }
            if ($videoId) {
                // ตรวจสอบว่าผู้ใช้เลือกเปิดเสียงหรือไม่ (enable_sound: 1=มีเสียง, 0=ปิดเสียง)
                $muteParam = !empty($enableSound) ? 'mute=0' : 'mute=1';
                // บังคับ playsinline=1, enablejsapi=1, iv_load_policy=3 (ปิด annotations ประหยัด CPU), disablekb=1, และความละเอียด 720p (vq=hd720) เพื่อความลื่นไหลสูงสุดบนจอ LED/สมาร์ททีวี
                $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $originParam = urlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $httpHost);
                return "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=1&{$muteParam}&controls=0&loop=1&playlist={$videoId}&rel=0&modestbranding=1&playsinline=1&enablejsapi=1&iv_load_policy=3&disablekb=1&fs=0&vq=hd720&origin={$originParam}&widget_referrer={$originParam}";
            }
            return $url;
        }

        // กรณีเป็นไฟล์วิดีโอ MP4 / WebM ตรง
        if ($type === 'video' || preg_match('/\.(mp4|webm|ogg)$/i', $url)) {
            if (!preg_match('/^(http|https):\/\//i', $url)) {
                $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$httpHost";
                $baseFolder = rtrim(str_replace('\\', '/', dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))))), '/');
                if (strpos($url, '/') !== 0) {
                    $url = $baseFolder . '/' . ltrim($url, '/');
                }
                return $baseUrl . $url;
            }
            return $url;
        }

        if ($type === 'canva') {
            // ตรวจสอบและแปลง Canva URL ให้เป็น Clean Embed View ตัดพารามิเตอร์ UTM tracking เพื่อโหลดได้เร็วขึ้น
            if (preg_match('/canva\.com\/design\/([a-zA-Z0-9_\-]+)\/([a-zA-Z0-9_\-]+)/i', $url, $matches)) {
                $designId = $matches[1];
                $designToken = $matches[2];
                $mode = (strpos($url, '/watch') !== false) ? 'watch' : 'view';
                return "https://www.canva.com/design/{$designId}/{$designToken}/{$mode}?embed";
            }
            if (strpos($url, 'view') !== false || strpos($url, 'design') !== false) {
                if (strpos($url, '?embed') === false && strpos($url, '&embed') === false) {
                    $sep = (strpos($url, '?') !== false) ? '&' : '?';
                    return $url . $sep . 'embed';
                }
            }
            return $url;
        }

        // กรณีเป็นไฟล์ภาพ
        if ($type === 'image') {
            // ถ้าเป็น local path สัมพัทธ์
            if (!preg_match('/^(http|https):\/\//i', $url)) {
                // เติม base path ไดนามิกตาม Host และ Base Directory จริง
                $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$httpHost";
                $baseFolder = rtrim(str_replace('\\', '/', dirname(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))))), '/');
                if (strpos($url, '/') !== 0) {
                    $url = $baseFolder . '/' . ltrim($url, '/');
                }
                return $baseUrl . $url;
            }
        }

        return $url;
    }

    $playlist = [];
    foreach ($rawItems as $row) {
        $playlist[] = [
            'item_id' => (int)$row['item_id'],
            'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
            'media_type' => $row['media_type'],
            'media_url' => $row['media_url'],
            'enable_sound' => (int)($row['enable_sound'] ?? 0),
            'embed_url' => formatMediaEmbed($row['media_type'], $row['media_url'], (int)($row['enable_sound'] ?? 0)),
            'duration_seconds' => max(5, (int)$row['duration_seconds'])
        ];
    }

    // 2. ถ้าไม่มีสไลด์ที่อนุมัติเลย ให้ใส่ Fallback Slide เพื่อไม่ให้จอดำ
    if (empty($playlist)) {
        $playlist[] = [
            'item_id' => 0,
            'title' => 'วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม',
            'media_type' => 'image',
            'media_url' => 'https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?q=80&w=1920&auto=format&fit=crop',
            'embed_url' => 'https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?q=80&w=1920&auto=format&fit=crop',
            'duration_seconds' => 12
        ];
    }

    // 3. ดึงการตั้งค่าแถบตัววิ่ง และการแสดงผล
    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM pr_display_settings");
    $settingsMap = [];
    while ($s = $stmtSettings->fetch(PDO::FETCH_ASSOC)) {
        $settingsMap[$s['setting_key']] = $s['setting_value'];
    }

    // คำนวณ Hash เพื่อให้หน้าจอเปรียบเทียบว่ามีเนื้อหาใหม่หรือไม่
    $hash = md5(json_encode($playlist) . ($settingsMap['ticker_text_th'] ?? ''));

    echo json_encode([
        'success' => true,
        'hash' => $hash,
        'total_items' => count($playlist),
        'server_time' => date('Y-m-d H:i:s'),
        'settings' => [
            'ticker_text_th' => $settingsMap['ticker_text_th'] ?? 'ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม',
            'ticker_text_en' => $settingsMap['ticker_text_en'] ?? 'Welcome to College of Music, Mahasarakham University',
            'ticker_text_cn' => $settingsMap['ticker_text_cn'] ?? '欢迎来到玛哈沙拉坎大学音乐学院',
            'default_slide_duration' => (int)($settingsMap['default_slide_duration'] ?? 10),
            'theme' => $settingsMap['screen1_theme'] ?? 'dark_gold'
        ],
        'playlist' => $playlist
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
