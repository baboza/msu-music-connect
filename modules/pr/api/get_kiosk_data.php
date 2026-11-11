<?php
/**
 * API: Get Interactive Kiosk Data for Screen 2
 * Supports 3 Languages: Thai ('th'), English ('en'), Chinese ('cn')
 * Returns 8 Categories + Active Contents + UI Dictionary
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../../config/db.php';

try {
    $lang = isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en', 'cn']) ? $_GET['lang'] : 'th';
    $category = trim($_GET['category'] ?? $_GET['cat'] ?? 'all');

    // 1. หมวดหมู่ทั้ง 8 หมวด พร้อมชื่อและไอคอน 3 ภาษา
    $categories = [
        'news' => [
            'key' => 'news',
            'icon' => 'bi-newspaper',
            'title_th' => 'ประกาศ / ข่าวสาร',
            'title_en' => 'Announcements & News',
            'title_cn' => '公告与新闻',
            'color' => '#3b82f6'
        ],
        'concerts' => [
            'key' => 'concerts',
            'icon' => 'bi-music-note-beamed',
            'title_th' => 'งานคอนเสิร์ต / การแสดง',
            'title_en' => 'Concerts & Events',
            'title_cn' => '音乐会与演出',
            'color' => '#8b5cf6'
        ],
        'about' => [
            'key' => 'about',
            'icon' => 'bi-building',
            'title_th' => 'รู้จักวิทยาลัย',
            'title_en' => 'About the College',
            'title_cn' => '了解学院',
            'color' => '#f59e0b'
        ],
        'programs' => [
            'key' => 'programs',
            'icon' => 'bi-mortarboard',
            'title_th' => 'หลักสูตรและการสมัคร',
            'title_en' => 'Programs & Admission',
            'title_cn' => '课程与招生',
            'color' => '#10b981'
        ],
        'staff' => [
            'key' => 'staff',
            'icon' => 'bi-people',
            'title_th' => 'ค้นหาบุคลากร',
            'title_en' => 'Staff & Faculty',
            'title_cn' => '教职工名录',
            'color' => '#06b6d4'
        ],
        'rooms' => [
            'key' => 'rooms',
            'icon' => 'bi-geo-alt',
            'title_th' => 'อาคารและห้องต่างๆ',
            'title_en' => 'Building & Rooms',
            'title_cn' => '建筑与琴房',
            'color' => '#ec4899'
        ],
        'services' => [
            'key' => 'services',
            'icon' => 'bi-headset',
            'title_th' => 'บริการนิสิต',
            'title_en' => 'Student Services',
            'title_cn' => '学生服务',
            'color' => '#6366f1'
        ],
        'achievements' => [
            'key' => 'achievements',
            'icon' => 'bi-trophy',
            'title_th' => 'ผลงานและความสำเร็จ',
            'title_en' => 'Achievements',
            'title_cn' => '荣誉与成就',
            'color' => '#eab308'
        ]
    ];

    // 2. UI Dictionary 3 ภาษา สำหรับส่วนควบคุมบนหน้าจอ Kiosk
    $uiDict = [
        'th' => [
            'welcome' => 'ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม',
            'subtitle' => 'สัมผัสหน้าจอเพื่อเลือกดูข้อมูลหรือสแกน QR Code เพื่ออ่านต่อบนสมาร์ตโฟน',
            'back' => 'ย้อนกลับ',
            'home' => 'หน้าหลัก',
            'search_placeholder' => 'พิมพ์คำค้นหา...',
            'no_data' => 'ยังไม่มีข้อมูลในหมวดนี้',
            'scan_qr' => 'สแกน QR เพื่อดูข้อมูลเพิ่มเติม',
            'close' => 'ปิด',
            'event_date' => 'วันที่จัดงาน',
            'location' => 'สถานที่',
            'details' => 'รายละเอียดเพิ่มเติม',
            'all_categories' => 'ทุกหมวดหมู่',
            'touch_to_explore' => 'แตะเพื่อเลือกหมวดหมู่',
            'idle_warning' => 'กำลังจะกลับสู่หน้าหลักใน',
            'seconds' => 'วินาที',
            'staff_sections' => [
                'all' => 'ทั้งหมด',
                'executives' => 'ผู้บริหาร',
                'secretariat' => 'สำนักงานเลขานุการ',
                'music_perf' => 'ภาควิชาการดุริยางคศิลป์',
                'music_edu' => 'ภาควิชาดนตรีศึกษา'
            ]
        ],
        'en' => [
            'welcome' => 'Welcome to College of Music, Mahasarakham University',
            'subtitle' => 'Touch the screen to explore information or scan QR Code to read on your mobile',
            'back' => 'Back',
            'home' => 'Home',
            'search_placeholder' => 'Search here...',
            'no_data' => 'No content found in this category',
            'scan_qr' => 'Scan QR Code for full details',
            'close' => 'Close',
            'event_date' => 'Event Date',
            'location' => 'Location',
            'details' => 'Read More',
            'all_categories' => 'All Categories',
            'touch_to_explore' => 'Touch to select category',
            'idle_warning' => 'Returning to home screen in',
            'seconds' => 'seconds',
            'staff_sections' => [
                'all' => 'All Staff',
                'executives' => 'Executives',
                'secretariat' => 'Secretariat Office',
                'music_perf' => 'Dept. of Music Performance',
                'music_edu' => 'Dept. of Music Education'
            ]
        ],
        'cn' => [
            'welcome' => '欢迎来到玛哈沙拉坎大学音乐学院',
            'subtitle' => '请轻触屏幕浏览信息，或扫描二维码在手机上继续查阅',
            'back' => '返回',
            'home' => '首页',
            'search_placeholder' => '输入关键词搜索...',
            'no_data' => '该分类下暂无内容',
            'scan_qr' => '扫描二维码查看详情',
            'close' => '关闭',
            'event_date' => '活动日期',
            'location' => '活动地点',
            'details' => '查看详情',
            'all_categories' => '全部分类',
            'touch_to_explore' => '点击进入分类',
            'idle_warning' => '即将返回主屏幕：',
            'seconds' => '秒',
            'staff_sections' => [
                'all' => '全部教职工',
                'executives' => '学院领导团队',
                'secretariat' => '学院办公室',
                'music_perf' => '音乐表演学系',
                'music_edu' => '音乐教育学系'
            ]
        ]
    ];

    // 3. ดึงข้อมูลเนื้อหาจากฐานข้อมูล
    $sql = "SELECT * FROM pr_kiosk_contents WHERE is_active = 1 ";
    $params = [];

    if ($category !== 'all' && isset($categories[$category])) {
        $sql .= " AND category_key = :cat ";
        $params[':cat'] = $category;
    }

    $sql .= " ORDER BY sort_order ASC, content_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        // เลือกภาษาตามที่ผู้ใช้ร้องขอ หากภาษาอังกฤษ/จีน ว่าง ให้ fallback เป็นภาษาไทย
        $title = !empty($r['title_' . $lang]) ? $r['title_' . $lang] : $r['title_th'];
        $detail = !empty($r['detail_' . $lang]) ? $r['detail_' . $lang] : $r['detail_th'];

        $thumb = $r['thumbnail_image'];
        if (empty($thumb) && $r['category_key'] === 'staff') {
            $thumb = 'assets/img/default-avatar.svg';
        }
        if (!empty($thumb) && !preg_match('/^(http|https):\/\//i', $thumb)) {
            $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$httpHost";
            if (strpos($thumb, '/') !== 0) {
                $thumb = '/music-e-office/' . ltrim($thumb, '/');
            }
            $thumb = $baseUrl . $thumb;
        }

        $is_exec = false;
        $dept_key = '';
        if ($r['category_key'] === 'staff') {
            $checkText = $detail . ' ' . $r['location'] . ' ' . $r['title_th'];
            if (
                preg_match('/(?:ตำแหน่ง|Position|职务):\s*(?:คณบดี|รองคณบดี|ผู้ช่วยคณบดี|หัวหน้าภาควิชา|หัวหน้าสำนักงาน|Dean|Associate Dean|Assistant Dean|Head)/iu', $detail) ||
                (int)$r['sort_order'] <= 8
            ) {
                $is_exec = true;
            }

            if (strpos($checkText, 'สำนักงานเลขานุการ') !== false || stripos($checkText, 'Secretariat') !== false) {
                $dept_key = 'secretariat';
            } elseif (strpos($checkText, 'ดนตรีศึกษา') !== false || stripos($checkText, 'Music Education') !== false) {
                $dept_key = 'music_edu';
            } elseif (strpos($checkText, 'ดุริยางคศาสตร์') !== false || strpos($checkText, 'ดุริยางคศิลป์') !== false || stripos($checkText, 'Performance') !== false) {
                $dept_key = 'music_perf';
            }
        }

        $qrLink = $r['qr_link'] ?? '';
        if (!empty($qrLink)) {
            $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
            // ปรับ host ของ QR link อัตโนมัติเมื่อขึ้นโฮสต์จริง (กรณีเซฟไว้เป็น localhost/127.0.0.1)
            $qrLink = preg_replace('/^https?:\/\/(localhost|127\.0\.0\.1)(?::\d+)?/i', "$protocol://$httpHost", $qrLink);
        }

        $items[] = [
            'content_id' => (int)$r['content_id'],
            'category_key' => $r['category_key'],
            'category_name' => $categories[$r['category_key']]['title_' . $lang] ?? $r['category_key'],
            'category_icon' => $categories[$r['category_key']]['icon'] ?? 'bi-info-circle',
            'category_color' => $categories[$r['category_key']]['color'] ?? '#3b82f6',
            'title' => $title,
            'detail' => $detail,
            'thumbnail' => $thumb,
            'media_url' => $r['media_url'],
            'qr_link' => $qrLink,
            'event_date' => $r['event_date'],
            'location' => $r['location'],
            'is_executive' => $is_exec,
            'dept_key' => $dept_key,
            'sort_order' => (int)$r['sort_order'],
            'view_count' => (int)$r['view_count']
        ];
    }

    // 4. ดึงการตั้งค่า Idle Timeout
    $stmtTimeout = $pdo->query("SELECT setting_value FROM pr_display_settings WHERE setting_key = 'kiosk_idle_timeout'");
    $idleTimeout = (int)($stmtTimeout->fetchColumn() ?: 60);

    echo json_encode([
        'success' => true,
        'current_lang' => $lang,
        'idle_timeout' => $idleTimeout,
        'categories' => $categories,
        'ui' => $uiDict[$lang],
        'total_items' => count($items),
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
