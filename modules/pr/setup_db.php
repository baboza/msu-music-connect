<?php
require_once __DIR__ . '/../../config/db.php';

try {
    // 1. ตารางป้ายประชาสัมพันธ์ (จอ 1 และส่งต่อจอ 2)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `pr_signage_items` (
      `item_id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` varchar(255) NOT NULL COMMENT 'ผู้ส่งคำขอ',
      `title` varchar(255) NOT NULL COMMENT 'ชื่อเรื่อง/หัวข้อประชาสัมพันธ์',
      `media_type` enum('image','canva','youtube') NOT NULL DEFAULT 'image',
      `media_url` text NOT NULL COMMENT 'พาธไฟล์รูปภาพ หรือลิงก์ Canva / YouTube',
      `duration_seconds` int(11) NOT NULL DEFAULT 10 COMMENT 'เวลาเล่นวน (วินาที)',
      `enable_sound` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=ปิดเสียง (Mute), 1=เปิดเสียง (Sound On)',
      `start_date` date NOT NULL COMMENT 'วันที่เริ่มแสดงผล',
      `end_date` date NOT NULL COMMENT 'วันสิ้นสุดการแสดงผล',
      `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'ลำดับการแสดงผล',
      `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
      `target_screen` enum('screen1_only','both') NOT NULL DEFAULT 'screen1_only',
      `approved_by` varchar(255) DEFAULT NULL COMMENT 'รหัสผู้พิจารณาอนุมัติ',
      `approved_at` datetime DEFAULT NULL,
      `reject_reason` text DEFAULT NULL COMMENT 'เหตุผลกรณีปฏิเสธ/ยกเลิก',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`item_id`),
      KEY `idx_status_date` (`status`, `start_date`, `end_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. ตารางเนื้อหาจอ Interactive Kiosk (จอ 2 - 8 หมวดหมู่)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `pr_kiosk_contents` (
      `content_id` int(11) NOT NULL AUTO_INCREMENT,
      `category_key` enum('news','concerts','about','programs','staff','rooms','services','achievements') NOT NULL,
      `title_th` varchar(255) NOT NULL,
      `title_en` varchar(255) DEFAULT NULL,
      `title_cn` varchar(255) DEFAULT NULL,
      `detail_th` text DEFAULT NULL,
      `detail_en` text DEFAULT NULL,
      `detail_cn` text DEFAULT NULL,
      `thumbnail_image` varchar(255) DEFAULT NULL,
      `media_url` text DEFAULT NULL COMMENT 'รูปภาพเพิ่มเติม/วิดีโอ',
      `qr_link` text DEFAULT NULL COMMENT 'ลิงก์สำหรับสร้าง QR Code ให้สแกนต่อบนมือถือ',
      `event_date` date DEFAULT NULL COMMENT 'สำหรับงานคอนเสิร์ต/กิจกรรม',
      `location` varchar(255) DEFAULT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `view_count` int(11) NOT NULL DEFAULT 0 COMMENT 'สถิติจำนวนครั้งที่มีการแตะดู',
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`content_id`),
      KEY `idx_cat_active` (`category_key`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. ตารางตั้งค่าจอแสดงผล
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS `pr_display_settings` (
      `setting_key` varchar(50) NOT NULL,
      `setting_value` text DEFAULT NULL,
      `description` varchar(255) DEFAULT NULL,
      PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed initial settings
    $settings = [
        ['ticker_text_th', 'ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม | College of Music, Mahasarakham University', 'ข้อความตัววิ่งภาษาไทย'],
        ['ticker_text_en', 'Welcome to College of Music, Mahasarakham University | Excellence in Music Education & Innovation', 'ข้อความตัววิ่งภาษาอังกฤษ'],
        ['ticker_text_cn', '欢迎来到玛哈沙拉坎大学音乐学院 | 卓越的音乐教育与表演', 'ข้อความตัววิ่งภาษาจีน'],
        ['kiosk_idle_timeout', '60', 'วินาทีที่ไม่มีการสัมผัสจอ ก่อนตัดกลับหน้าหลัก'],
        ['default_slide_duration', '10', 'เวลาแสดงภาพนิ่งเริ่มต้น (วินาที)'],
        ['screen1_theme', 'dark_gold', 'ธีมสีของจอที่ 1'],
        ['screen2_theme', 'royal_music', 'ธีมสีของจอที่ 2']
    ];

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM pr_display_settings WHERE setting_key = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO pr_display_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");

    foreach ($settings as $s) {
        $stmtCheck->execute([$s[0]]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmtInsert->execute($s);
        }
    }

    // Seed Initial Interactive Sample Contents for 8 Categories so Screen 2 looks stunning out of the box
    $countKiosk = $pdo->query("SELECT COUNT(*) FROM pr_kiosk_contents")->fetchColumn();
    if ($countKiosk == 0) {
        $sampleItems = [
            // 1. News
            [
                'news',
                'เปิดรับสมัครนิสิตใหม่ ระดับปริญญาตรี ประจำปีการศึกษา 2570',
                'Undergraduate Admission Open for Academic Year 2027',
                '2027学年本科招生正式开始',
                'วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม เปิดรับสมัครบุคคลเข้าศึกษาต่อระดับปริญญาตรี หลักสูตรดุริยางคศาสตรบัณฑิต และการศึกษาบัณฑิต สมัครได้ผ่านระบบ TCAS และรับตรง',
                'College of Music, MSU invites applications for undergraduate degree programs in Music Performance, Music Education and Thai Music.',
                '玛哈沙拉坎大学音乐学院招收音乐表演、音乐教育及泰国音乐专业本科生。欢迎国际学生报考。',
                'https://picsum.photos/id/1082/800/450',
                'https://music.msu.ac.th/admission',
                null,
                'วิทยาลัยดุริยางคศิลป์ มมส'
            ],
            // 2. Concerts
            [
                'concerts',
                'MSU Symphony Orchestra Annual Gala Concert 2026',
                'MSU Symphony Orchestra Annual Gala Concert 2026',
                '2026玛哈沙拉坎大学交响乐团年度盛典音乐会',
                'การแสดงดนตรีซิมโฟนีออร์เคสตราครั้งยิ่งใหญ่ บรรเลงบทเพลงคลาสสิกระดับโลกและบทเพลงไทยประยุกต์ โดยคณาจารย์และนิสิตวิทยาลัยดุริยางคศิลป์ ณ หอแสดงดนตรีวิทยาลัย',
                'Grand Symphony Orchestra performance featuring classical masterpieces and contemporary Thai compositions.',
                '盛大交响乐团演出，呈献世界经典与当代泰国音乐作品，由音乐学院师生联合呈现。',
                'https://picsum.photos/id/1080/800/450',
                'https://music.msu.ac.th/events/concert2026',
                date('Y-m-d', strtotime('+7 days')),
                'หอแสดงดนตรีวิทยาลัยดุริยางคศิลป์'
            ],
            // 3. About
            [
                'about',
                'วิสัยทัศน์และพันธกิจ วิทยาลัยดุริยางคศิลป์',
                'Vision & Mission - College of Music, MSU',
                '音乐学院愿景与使命',
                'มุ่งสู่การเป็นศูนย์กลางการศึกษา การวิจัย และการสร้างสรรค์นวัตกรรมทางดนตรีแห่งภูมิภาคอาเซียน โดยผสมผสานดนตรีสากลและดนตรีพื้นเมืองอีสานสู่เวทีโลก',
                'To be the ASEAN center of music education, research, and creative innovation, elevating Isan and international music to global stages.',
                '致力于成为东盟音乐教育、研究与创意创新的中心，推动国际音乐与依善本土音乐走向世界舞台。',
                'https://picsum.photos/id/1059/800/450',
                'https://music.msu.ac.th/about',
                null,
                'อาคารวิทยาลัยดุริยางคศิลป์'
            ],
            // 4. Programs
            [
                'programs',
                'หลักสูตรดุริยางคศาสตรบัณฑิต (ดย.บ.) 4 ปี',
                'Bachelor of Music Program (B.M.) 4-Year',
                '音乐学士学位课程 (4年制)',
                'วิชาเอกดนตรีตะวันตก (Classic, Jazz, Contemporary), ดนตรีไทยและดนตรีพื้นเมืองอีสาน, เทคโนโลยีและนวัตกรรมดนตรี มีห้องปฏิบัติการและสตูดิโอมาตรฐานระดับสากล',
                'Majors in Western Music, Jazz, Thai Traditional & Isan Music, Music Production and Music Technology with international standard studios.',
                '专业方向包括西方音乐、爵士乐、泰国传统及依善民间音乐、音乐制作与音乐科技。配备高标准录音棚。',
                'https://picsum.photos/id/1033/800/450',
                'https://music.msu.ac.th/programs/bm',
                null,
                'สำนักงานวิชาการ อาคารดุริยางคศิลป์'
            ],
            // 5. Staff
            [
                'staff',
                'ทำเนียบคณาจารย์และผู้เชี่ยวชาญทางดนตรี',
                'Faculty Members & Music Specialists Directory',
                '教师名录与音乐专家指南',
                'พบกับคณาจารย์ผู้ทรงคุณวุฒิด้านดนตรีระดับแนวหน้าของประเทศไทย ครอบคลุมทุกกลุ่มเครื่องดนตรี เครื่องสาย เครื่องเป่า คีย์บอร์ด ดนตรีอีสาน และการประพันธ์เพลง',
                'Discover our experienced faculty covering strings, brass, woodwinds, percussion, keyboard, voice, and composition.',
                '认识由各乐器及理论方向专家组成的师资团队，涵盖弦乐、管乐、钢琴、声乐及作曲方向。',
                'https://picsum.photos/id/1062/800/450',
                'https://music.msu.ac.th/faculty',
                null,
                'ชั้น 2-3 อาคารวิทยาลัยดุริยางคศิลป์'
            ],
            // 6. Rooms
            [
                'rooms',
                'แผนผังอาคารและห้องปฏิบัติการทางดนตรี',
                'Campus Building Directory & Wayfinding',
                '学院建筑与琴房分布指南',
                'ชั้น 1: หอแสดงดนตรี, ห้องประชุม, คาเฟ่\nชั้น 2: ห้องสำนักงาน, ห้องพักอาจารย์, ห้องบรรยาย\nชั้น 3: สตูดิโอบันทึกเสียง, ห้อง Mac Lab\nชั้น 4: ห้องซ้อมรวมวง Ensemble และห้องซ้อมเดี่ยว 50 ห้อง',
                'Floor 1: Concert Hall & Cafe | Floor 2: Office & Classrooms | Floor 3: Sound Studios & Mac Lab | Floor 4: Practice Rooms.',
                '1层：音乐厅与大厅 | 2层：行政办公室与教室 | 3层：录音棚与电脑音乐室 | 4层：单人与合奏琴房。',
                'https://picsum.photos/id/1048/800/450',
                'https://music.msu.ac.th/facilities',
                null,
                'อาคารวิทยาลัยดุริยางคศิลป์ มมส'
            ],
            // 7. Services
            [
                'services',
                'ระบบจองห้องซ้อมดนตรี และบริการยืมคืนเครื่องดนตรี',
                'Practice Room Booking & Instrument Loan Services',
                '琴房预约与乐器借还服务',
                'นิสิตสามารถจองห้องซ้อมเดี่ยว ห้องซ้อมวง และขอยืมเครื่องดนตรีผ่านระบบ e-Office ได้ตลอด 24 ชั่วโมง พร้อมระบบแจ้งเตือนผ่าน Telegram/LINE',
                'Students can book individual practice rooms, ensemble rooms, and borrow instruments 24/7 via the e-Office portal.',
                '学生可通过学院 e-Office 系统全天候在线预约单人琴房、合奏室及借还乐器。',
                'https://picsum.photos/id/1070/800/450',
                'http://localhost/music-e-office/modules/practice_rooms/',
                null,
                'เคาน์เตอร์บริการโสตฯ ชั้น 1'
            ],
            // 8. Achievements
            [
                'achievements',
                'ผลงานและความสำเร็จระดับชาติและนานาชาติ',
                'National & International Awards and Achievements',
                '国家与国际级获奖成果',
                'นิสิตและวงดนตรีวิทยาลัยดุริยางคศิลป์ มมส คว้าถ้วยพระราชทานและการแข่งขันดนตรีระดับนานาชาติ ตอกย้ำความเป็นเลิศด้านดนตรีอย่างต่อเนื่อง',
                'Celebrating prestigious royal trophies and international competition victories won by our students and faculty ensembles.',
                '热烈庆祝音乐学院师生及乐团在各项国家级和国际音乐赛事中荣获最高奖项与殊荣。',
                'https://picsum.photos/id/1058/800/450',
                'https://music.msu.ac.th/achievements',
                null,
                'หอเกียรติยศ ชั้น 1'
            ]
        ];

        $insKiosk = $pdo->prepare("
            INSERT INTO pr_kiosk_contents 
            (category_key, title_th, title_en, title_cn, detail_th, detail_en, detail_cn, thumbnail_image, qr_link, event_date, location, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
        ");

        foreach ($sampleItems as $idx => $item) {
            $insKiosk->execute([
                $item[0], $item[1], $item[2], $item[3],
                $item[4], $item[5], $item[6],
                $item[7], $item[8], $item[9], $item[10]
            ]);
        }
    }

    // Seed Sample Approved Signage for Screen 1
    $countSignage = $pdo->query("SELECT COUNT(*) FROM pr_signage_items")->fetchColumn();
    if ($countSignage == 0) {
        $pdo->exec("
        INSERT INTO `pr_signage_items` 
        (`user_id`, `title`, `media_type`, `media_url`, `duration_seconds`, `start_date`, `end_date`, `sort_order`, `status`, `target_screen`, `approved_by`, `approved_at`)
        VALUES
        ('admin', 'ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม', 'image', 'https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?q=80&w=1920&auto=format&fit=crop', 12, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1, 'approved', 'both', 'admin', NOW()),
        ('admin', 'MSU Symphony Orchestra Annual Concert 2026', 'image', 'https://images.unsplash.com/photo-1465847899084-d164df4dedc6?q=80&w=1920&auto=format&fit=crop', 15, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 2, 'approved', 'both', 'admin', NOW()),
        ('admin', 'แนะนำวิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม (Video Presentation)', 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 30, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90 DAY), 3, 'approved', 'screen1_only', 'admin', NOW());
        ");
    }

    echo "DATABASE SETUP SUCCESSFUL!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
