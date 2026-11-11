<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Video Playback on Screen 1</title>
    <style>
        body {
            background: #111;
            color: #fff;
            font-family: sans-serif;
            padding: 20px;
        }
        h1 { color: #d4af37; font-size: 1.5rem; }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        .box {
            background: #222;
            border: 2px solid #444;
            border-radius: 8px;
            padding: 10px;
        }
        .box h3 { margin-top: 0; font-size: 1rem; color: #38bdf8; }
        iframe, video {
            width: 100%;
            height: 280px;
            border: none;
            background: #000;
        }
    </style>
</head>
<body>
    <h1>เครื่องมือทดสอบการเล่นวิดีโอบนจอ (Video Diagnostic Tool)</h1>
    <p>เปิดหน้านี้บนจอ 98" เพื่อดูว่ากล่องทีวีรองรับการแสดงผลแบบใดและอันไหนไม่ขึ้นจอเขียว:</p>

    <div class="grid">
        <!-- 1. Clean YouTube Embed -->
        <div class="box">
            <h3>1. YouTube Embed สะอาด (Clean URL - No extra flags)</h3>
            <iframe src="https://www.youtube.com/embed/TbyTVN6Gkeo?autoplay=1&mute=1" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>

        <!-- 2. YouTube NoCookie -->
        <div class="box">
            <h3>2. YouTube NoCookie Embed</h3>
            <iframe src="https://www.youtube-nocookie.com/embed/TbyTVN6Gkeo?autoplay=1&mute=1" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>

        <!-- 3. YouTube Mobile Embed -->
        <div class="box">
            <h3>3. YouTube Mobile Site (m.youtube.com)</h3>
            <iframe src="https://m.youtube.com/watch?v=TbyTVN6Gkeo" allow="autoplay; encrypted-media" allowfullscreen></iframe>
        </div>

        <!-- 4. HTML5 Video (MP4) -->
        <div class="box">
            <h3>4. ไฟล์วิดีโอตรง HTML5 MP4 (Native Video)</h3>
            <video src="test.mp4" controls autoplay muted loop playsinline></video>
        </div>
    </div>
</body>
</html>
