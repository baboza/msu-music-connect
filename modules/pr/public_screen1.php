<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>MSU Music Digital Signage · Screen 1 (LED Video Wall 98")</title>
    
    <!-- Ultra-Fast DNS Prefetch & Preconnect for YouTube & Canva -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://www.youtube-nocookie.com">
    <link rel="preconnect" href="https://i.ytimg.com">
    <link rel="preconnect" href="https://www.canva.com">
    <link rel="dns-prefetch" href="https://www.youtube-nocookie.com">
    <link rel="dns-prefetch" href="https://i.ytimg.com">
    <link rel="dns-prefetch" href="https://www.canva.com">
    
    <!-- Google Fonts & Bootstrap Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@300;400;500;600;700&family=Prompt:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Cinzel:wght@600;700;800&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/google-sans" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --bg-deep: #05070c;
            --gold-primary: #d4af37;
            --gold-light: #f6e8b8;
            --gold-gradient: linear-gradient(135deg, #ffd700 0%, #d4af37 50%, #b8860b 100%);
            --cyan-accent: #38bdf8;
            --text-main: #ffffff;
            --text-sub: #94a3b8;
            --ticker-height: 64px;
            --top-bar-height: 90px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        body, html {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: var(--bg-deep);
            font-family: 'Google Sans', 'Prompt', 'Sarabun', sans-serif;
            color: var(--text-main);
            touch-action: manipulation;
            -webkit-text-size-adjust: 100%;
        }

        body.hide-cursor {
            cursor: none;
        }

        /* ---------------- MAIN CONTAINER ---------------- */
        #display-container {
            position: relative;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            background-color: var(--bg-deep);
            contain: strict;
        }

        /* Subtle Ambient Stage Beam (Lightweight pure gradient) */
        .stage-ambient-glow {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80vw;
            height: 40vh;
            background: radial-gradient(ellipse at center, rgba(212, 175, 55, 0.06) 0%, rgba(56, 189, 248, 0.02) 40%, transparent 70%);
            pointer-events: none;
            z-index: 5;
        }

        /* ---------------- TOP HUD BAR ---------------- */
        #top-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: var(--top-bar-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            background: linear-gradient(180deg, rgba(8, 12, 22, 0.98) 0%, rgba(12, 16, 28, 0.94) 100%);
            border-bottom: 1.5px solid rgba(212, 175, 55, 0.35);
            z-index: 60;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            contain: layout style;
        }

        .brand-badge {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-logo-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.2) 0%, rgba(15, 20, 36, 0.9) 100%);
            border: 1.5px solid rgba(212, 175, 55, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo {
            height: 42px;
            width: auto;
            object-fit: contain;
        }

        .brand-title .th {
            font-size: 1.3rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.25;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-title .th .highlight {
            background: linear-gradient(135deg, #ffd700 0%, #f6e8b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-title .en {
            font-size: 0.8rem;
            font-weight: 400;
            color: #94a3b8;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        /* Center Audio Visualizer Motif */
        .header-center-visual {
            display: flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 8px 18px;
            border-radius: 40px;
        }

        .soundwave-box {
            display: flex;
            align-items: center;
            gap: 3px;
            height: 18px;
        }

        .soundwave-bar {
            width: 3px;
            border-radius: 3px;
            background: linear-gradient(to top, var(--gold-primary), var(--cyan-accent));
            animation: soundwave-pulse 1.4s ease-in-out infinite alternate;
            will-change: transform;
        }

        .soundwave-bar:nth-child(1) { height: 8px; animation-delay: 0.1s; }
        .soundwave-bar:nth-child(2) { height: 16px; animation-delay: 0.3s; }
        .soundwave-bar:nth-child(3) { height: 10px; animation-delay: 0.2s; }
        .soundwave-bar:nth-child(4) { height: 18px; animation-delay: 0.4s; }
        .soundwave-bar:nth-child(5) { height: 12px; animation-delay: 0.15s; }

        @keyframes soundwave-pulse {
            0% { transform: scaleY(0.4); opacity: 0.6; }
            100% { transform: scaleY(1.2); opacity: 1; }
        }

        /* Pause soundwave animation when video is active to save 100% GPU for video decoding */
        body.video-active .soundwave-bar {
            animation-play-state: paused;
            opacity: 0.7;
        }

        .live-tag {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .live-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #22c55e;
            box-shadow: 0 0 8px #22c55e;
        }

        .slide-counter-pill {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--gold-light);
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.3);
            padding: 3px 12px;
            border-radius: 20px;
            font-family: 'Cinzel', sans-serif;
            letter-spacing: 0.8px;
        }

        /* Right HUD: Clock & Date */
        .hud-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .clock-widget {
            text-align: right;
            border-left: 1px solid rgba(255, 255, 255, 0.1);
            padding-left: 20px;
        }

        .clock-time {
            font-size: 1.7rem;
            font-weight: 700;
            color: #ffffff;
            font-family: 'Cinzel', sans-serif;
            letter-spacing: 1.2px;
            line-height: 1.1;
        }

        .clock-time .sec {
            font-size: 1rem;
            color: var(--gold-light);
        }

        .clock-date {
            font-size: 0.78rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        /* ---------------- SLIDE STAGE (Hardware Overlay Compliant) ---------------- */
        #slide-stage {
            position: absolute;
            top: var(--top-bar-height);
            left: 0;
            width: 100%;
            height: calc(100% - var(--top-bar-height) - var(--ticker-height));
            z-index: 10;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #000000;
            contain: strict;
        }

        /* Image Slide Layer: smooth cross-fade */
        .slide-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.6s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #000000;
            pointer-events: none;
            contain: strict;
        }

        .slide-layer.active {
            opacity: 1;
            visibility: visible;
            z-index: 20;
            pointer-events: auto;
        }

        /* Video / YouTube layer: STRICTLY FLAT 2D, ZERO 3D COMPOSITOR CONFLICT
           Eliminates Android MediaCodec YUV Green-Screen and Stutter */
        .slide-layer.is-video-layer {
            transform: none !important;
            transition: none !important;
            background-color: #000000;
            display: none;
        }

        .slide-layer.is-video-layer.active {
            transform: none !important;
            opacity: 1 !important;
            visibility: visible !important;
            display: flex !important;
            z-index: 25;
            pointer-events: auto;
        }

        /* Ken-Burns exclusively for static images */
        .slide-layer[data-type="image"].active .image-foreground {
            animation: ken-burns 16s ease-out forwards;
            will-change: transform;
        }

        @keyframes ken-burns {
            0% { transform: scale(1); }
            100% { transform: scale(1.04); }
        }

        .image-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* 0% GPU Gaussian Blur overhead: uses clean dark backdrop opacity */
        .image-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            opacity: 0.22;
            z-index: 1;
        }

        .image-foreground {
            position: relative;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7);
            z-index: 2;
        }

        /* Iframe container: strictly isolated 2D flat layer */
        .iframe-container {
            position: relative;
            width: 100%;
            height: 100%;
            border: none;
            background-color: #000000;
            display: flex;
            align-items: center;
            justify-content: center;
            contain: strict;
        }

        .iframe-container iframe,
        .iframe-container video {
            width: 100%;
            height: 100%;
            border: none;
            background-color: #000000;
            display: block;
            object-fit: contain;
        }

        /* YouTube Loading Placeholder */
        .video-loading-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #000000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 3;
            color: var(--gold-light);
            transition: opacity 0.4s ease;
        }

        .video-loading-placeholder.hide {
            opacity: 0;
            pointer-events: none;
        }

        .video-loading-spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(212, 175, 55, 0.2);
            border-top-color: var(--gold-primary);
            border-radius: 50%;
            animation: spin-loader 0.8s linear infinite;
            margin-bottom: 14px;
        }

        @keyframes spin-loader {
            to { transform: rotate(360deg); }
        }

        /* ---------------- PROGRESS BAR (Pure CSS Hardware Animation) ---------------- */
        #progress-bar-container {
            position: absolute;
            bottom: var(--ticker-height);
            left: 0;
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.08);
            z-index: 55;
            overflow: hidden;
            contain: strict;
        }

        #progress-bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #d4af37 0%, #ffd700 50%, #38bdf8 100%);
            box-shadow: 0 0 8px rgba(212, 175, 55, 0.7);
            will-change: width;
        }

        /* ---------------- BOTTOM TICKER ---------------- */
        #bottom-ticker {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: var(--ticker-height);
            background: linear-gradient(180deg, rgba(10, 14, 26, 0.98) 0%, rgba(6, 8, 14, 0.99) 100%);
            border-top: 1px solid rgba(212, 175, 55, 0.35);
            display: flex;
            align-items: center;
            z-index: 60;
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.5);
            contain: strict;
        }

        .ticker-label {
            height: 100%;
            padding: 0 26px;
            background: linear-gradient(135deg, #b8860b 0%, #d4af37 50%, #f6e8b8 100%);
            color: #07090e;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.98rem;
            white-space: nowrap;
            z-index: 2;
        }

        .ticker-viewport {
            flex: 1;
            overflow: hidden;
            white-space: nowrap;
            position: relative;
        }

        .ticker-content {
            display: inline-block;
            white-space: nowrap;
            font-size: 1.18rem;
            font-weight: 500;
            color: #f8fafc;
            animation: marquee 36s linear infinite;
            padding-left: 100%;
            will-change: transform;
            transform: translate3d(0, 0, 0);
        }

        .ticker-bullet {
            color: var(--gold-primary);
            margin: 0 20px;
            font-size: 0.85rem;
        }

        @keyframes marquee {
            0% { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-100%, 0, 0); }
        }

        /* Manual Navigation Touch Buttons */
        .stage-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(8, 11, 20, 0.8);
            border: 1px solid rgba(212, 175, 55, 0.4);
            color: #ffffff;
            font-size: 1.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 40;
            opacity: 0;
            transition: all 0.25s ease;
        }

        .stage-nav-btn:hover {
            background: rgba(212, 175, 55, 0.35);
            border-color: #ffd700;
            color: #ffd700;
            transform: translateY(-50%) scale(1.08);
        }

        .stage-nav-prev { left: 20px; }
        .stage-nav-next { right: 20px; }

        body:hover .stage-nav-btn {
            opacity: 0.75;
        }

        /* Fullscreen Toggle Button */
        .btn-fs-touch {
            background: rgba(22, 31, 56, 0.9);
            border: 1.5px solid rgba(212, 175, 55, 0.4);
            color: #ffffff;
            padding: 7px 16px;
            border-radius: 30px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .btn-fs-touch:hover, .btn-fs-touch:active {
            background: var(--gold-gradient);
            color: #07090e;
            border-color: #ffd700;
        }

        /* Fullscreen Guide Hint */
        #fs-hint {
            position: absolute;
            top: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(8, 12, 22, 0.95);
            border: 1.5px solid rgba(212, 175, 55, 0.4);
            padding: 8px 22px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #f1f5f9;
            z-index: 100;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
        }
    </style>
</head>
<body>

    <div id="display-container">
        <!-- Ambient Stage Glow Beam -->
        <div class="stage-ambient-glow"></div>

        <!-- Floating Fullscreen Hint -->
        <div id="fs-hint" onclick="toggleFullscreen()" title="แตะเพื่อเปิดโหมดเต็มจอ">
            <i class="bi bi-arrows-fullscreen text-gold me-1"></i> แตะปุ่ม "เต็มจอ" หรือแตะตรงนี้เพื่อเปิดโหมดเต็มขอบ 98"
        </div>

        <!-- Top Header Bar (Brand HUD) -->
        <header id="top-bar">
            <!-- Left: Brand Logo & Title -->
            <div class="brand-badge">
                <div class="brand-logo-wrapper">
                    <img src="../../includes/logo.png" alt="MSU Music Logo" class="brand-logo" onerror="this.style.display='none'">
                </div>
                <div class="brand-title">
                    <div class="th">
                        วิทยาลัยดุริยางคศิลป์ <span class="highlight">มหาวิทยาลัยมหาสารคาม</span>
                    </div>
                    <div class="en">College of Music, Mahasarakham University</div>
                </div>
            </div>

            <!-- Center: Audio Wave & Live Indicator -->
            <div class="header-center-visual">
                <div class="soundwave-box">
                    <div class="soundwave-bar"></div>
                    <div class="soundwave-bar"></div>
                    <div class="soundwave-bar"></div>
                    <div class="soundwave-bar"></div>
                    <div class="soundwave-bar"></div>
                </div>

                <div class="live-tag">
                    <span class="live-pulse-dot"></span>
                    <span>BROADCAST LIVE</span>
                </div>

                <div class="slide-counter-pill" id="slide-counter">-- / --</div>
                <div id="slide-sound-badge" style="display: none;"></div>
            </div>

            <!-- Right: Fullscreen Touch Button + Clock & Date -->
            <div class="hud-right">
                <button type="button" class="btn-fs-touch" id="btn-fs-touch" onclick="toggleFullscreen()" title="แตะเพื่อเปิด/ปิดจอเต็ม (Fullscreen)">
                    <i class="bi bi-arrows-fullscreen"></i>
                    <span id="ui-fs-text">เต็มจอ</span>
                </button>
                <div class="clock-widget">
                    <div class="clock-time" id="clock-time">--:--<span class="sec">:--</span></div>
                    <div class="clock-date" id="clock-date">กำลังโหลดวันเวลา...</div>
                </div>
            </div>
        </header>

        <!-- Slide Stage Area -->
        <main id="slide-stage">
            <div class="slide-layer active" id="initial-loader" style="background:#000000; display:flex; flex-direction:column; justify-content:center; align-items:center;">
                <div style="font-size:2.6rem; color:var(--gold-primary); margin-bottom:16px;">
                    <i class="bi bi-music-note-beamed"></i>
                </div>
                <h2 style="font-weight:600; font-size:1.4rem; color:#ffffff; margin-bottom:8px;">วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม</h2>
                <p style="font-size:0.95rem; color:#94a3b8;">กำลังเตรียมคิวประชาสัมพันธ์อัจฉริยะ (Ultra-Smooth Engine)...</p>
            </div>
        </main>

        <!-- Manual Navigation Touch Buttons -->
        <button type="button" class="stage-nav-btn stage-nav-prev" onclick="prevSlide()" title="สไลด์ก่อนหน้า">
            <i class="bi bi-chevron-left"></i>
        </button>
        <button type="button" class="stage-nav-btn stage-nav-next" onclick="nextSlide()" title="สไลด์ถัดไป">
            <i class="bi bi-chevron-right"></i>
        </button>

        <!-- Slide Duration Progress Bar -->
        <div id="progress-bar-container">
            <div id="progress-bar"></div>
        </div>

        <!-- Bottom Marquee Ticker -->
        <footer id="bottom-ticker">
            <div class="ticker-label">
                <i class="bi bi-broadcast"></i> ประชาสัมพันธ์
            </div>
            <div class="ticker-viewport">
                <div class="ticker-content" id="ticker-text">
                    ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม <span class="ticker-bullet">✦</span> College of Music, Mahasarakham University <span class="ticker-bullet">✦</span> 欢迎来到玛哈沙拉坎大学音乐学院
                </div>
            </div>
        </footer>
    </div>

    <script>
        /**
         * Ultra-Smooth Smart Digital Signage Engine (Screen 1 - 98" 4K Video Wall)
         * Key Optimizations:
         * 1. 0% CPU Progress Bar: Uses CSS Hardware Transitions instead of 100ms JavaScript polling timers.
         * 2. Zero Gaussian Blur Overhead: Replaced filter:blur with lightweight backdrop opacity.
         * 3. Isolated Render Tree: contain: strict prevents ticker and clock repaints from impacting video decoding.
         * 4. Locked 720p YouTube Quality: Dispatches setPlaybackQuality('hd720') via postMessage for smooth frame delivery.
         * 5. Lightweight youtube-nocookie embed without tracking code & annotations.
         */
        const CONFIG = {
            API_URL: 'api/get_signage_playlist.php',
            POLL_INTERVAL_MS: 30000
        };

        // Cache DOM elements
        const dom = {
            stage: document.getElementById('slide-stage'),
            clockTime: document.getElementById('clock-time'),
            clockDate: document.getElementById('clock-date'),
            slideCounter: document.getElementById('slide-counter'),
            soundBadge: document.getElementById('slide-sound-badge'),
            progressBar: document.getElementById('progress-bar'),
            tickerText: document.getElementById('ticker-text'),
            fsBtn: document.getElementById('btn-fs-touch'),
            fsHint: document.getElementById('fs-hint')
        };

        let state = {
            currentHash: '',
            playlist: [],
            currentIndex: 0,
            slideTimer: null,
            isTransitioning: false,
            cachedDateStr: ''
        };

        // 1. Digital Clock & Calendar
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            dom.clockTime.innerHTML = `${h}:${m}<span class="sec">:${s}</span>`;

            // Cache date string to avoid daily recalculations every second
            const dateKey = `${now.getFullYear()}-${now.getMonth()}-${now.getDate()}`;
            if (dateKey !== state.cachedDateStr) {
                state.cachedDateStr = dateKey;
                const thaiDays = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
                const thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
                dom.clockDate.textContent = `วัน${thaiDays[now.getDay()]}ที่ ${now.getDate()} ${thaiMonths[now.getMonth()]} ${now.getFullYear() + 543}`;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 2. YouTube postMessage Command Dispatcher
        function postToYouTube(iframe, func, args = []) {
            if (!iframe || !iframe.contentWindow) return;
            try {
                iframe.contentWindow.postMessage(JSON.stringify({
                    event: 'command',
                    func: func,
                    args: args
                }), '*');
            } catch (err) {}
        }

        // 3. Sync Slide Layers for Images & Canva
        function syncSlidePool(items) {
            const validIds = new Set(items.map(it => String(it.item_id)));

            // Remove layers no longer in playlist
            dom.stage.querySelectorAll('.slide-layer[data-id]').forEach(layer => {
                if (!validIds.has(layer.getAttribute('data-id'))) {
                    layer.remove();
                }
            });

            // Create persistent shell for each item
            items.forEach((item) => {
                let layer = dom.stage.querySelector(`.slide-layer[data-id="${item.item_id}"]`);
                if (!layer) {
                    layer = document.createElement('div');
                    layer.className = 'slide-layer';
                    layer.setAttribute('data-id', item.item_id);
                    layer.setAttribute('data-type', item.media_type);

                    if (item.media_type === 'youtube' || item.media_type === 'video' || item.media_type === 'canva') {
                        layer.classList.add('is-video-layer');
                    }

                    if (item.media_type === 'image') {
                        layer.innerHTML = `
                            <div class="image-container">
                                <div class="image-backdrop" style="background-image: url('${item.embed_url}');"></div>
                                <img src="${item.embed_url}" alt="${item.title || 'MSU Music'}" class="image-foreground" loading="eager">
                            </div>
                        `;
                    } else if (item.media_type === 'canva') {
                        layer.innerHTML = `
                            <div class="iframe-container">
                                <iframe id="canva-player-${item.item_id}"
                                    src="${item.embed_url}" 
                                    allow="autoplay; encrypted-media" 
                                    loading="eager"
                                    allowfullscreen>
                                </iframe>
                            </div>
                        `;
                    } else if (item.media_type === 'youtube' || item.media_type === 'video') {
                        layer.innerHTML = `
                            <div class="iframe-container" id="yt-box-${item.item_id}">
                                <div class="video-loading-placeholder" id="yt-loader-${item.item_id}">
                                    <div class="video-loading-spinner"></div>
                                    <div style="font-size:1.1rem; font-weight:600;">กำลังโหลดวิดีโอ...</div>
                                </div>
                            </div>
                        `;
                    }

                    dom.stage.appendChild(layer);
                }
            });
        }

        // 4. Fetch Playlist & Background Sync
        async function fetchPlaylist() {
            try {
                const response = await fetch(CONFIG.API_URL + '?t=' + Date.now());
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();

                if (data.success && data.playlist && data.playlist.length > 0) {
                    if (data.hash !== state.currentHash) {
                        state.currentHash = data.hash;

                        if (data.settings && dom.tickerText) {
                            dom.tickerText.innerHTML = `${data.settings.ticker_text_th} <span class="ticker-bullet">✦</span> ${data.settings.ticker_text_en} <span class="ticker-bullet">✦</span> ${data.settings.ticker_text_cn}`;
                        }

                        const isFirstLoad = state.playlist.length === 0;
                        state.playlist = data.playlist;

                        syncSlidePool(data.playlist);

                        if (isFirstLoad) {
                            const loader = document.getElementById('initial-loader');
                            if (loader) loader.remove();
                            showSlide(0);
                        } else {
                            updateSlideCounter();
                        }
                    }
                }
            } catch (err) {}
        }

        function updateSlideCounter() {
            if (!dom.slideCounter) return;
            const total = state.playlist.length;
            const current = state.currentIndex + 1;
            dom.slideCounter.textContent = `${String(current).padStart(2, '0')} / ${String(total).padStart(2, '0')}`;
        }

        // 5. Render and Show Slide
        function showSlide(index) {
            if (state.playlist.length === 0 || state.isTransitioning) return;
            state.isTransitioning = true;

            const item = state.playlist[index];
            state.currentIndex = index;
            const durationMs = (item.duration_seconds || 10) * 1000;

            updateSlideCounter();

            // Sound indicator badge on HUD
            if (dom.soundBadge) {
                if (item.media_type === 'youtube') {
                    dom.soundBadge.innerHTML = item.enable_sound
                        ? '<span style="font-size:0.78rem; font-weight:600; color:#86efac; background:rgba(34,197,94,0.18); border:1px solid rgba(34,197,94,0.4); padding:2px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px;"><i class="bi bi-volume-up-fill"></i> เปิดเสียง</span>'
                        : '<span style="font-size:0.78rem; font-weight:600; color:#cbd5e1; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); padding:2px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px;"><i class="bi bi-volume-mute-fill"></i> ปิดเสียง</span>';
                    dom.soundBadge.style.display = 'inline-block';
                } else {
                    dom.soundBadge.style.display = 'none';
                }
            }

            const newLayer = dom.stage.querySelector(`.slide-layer[data-id="${item.item_id}"]`);
            const oldLayer = dom.stage.querySelector('.slide-layer.active');

            // 1) Cleanup previous active layer
            if (oldLayer && oldLayer !== newLayer) {
                const oldType = oldLayer.getAttribute('data-type');
                if (oldType === 'youtube') {
                    const oldIframe = oldLayer.querySelector('iframe');
                    if (oldIframe) {
                        postToYouTube(oldIframe, 'pauseVideo');
                        setTimeout(() => {
                            const box = oldLayer.querySelector('.iframe-container');
                            if (box) {
                                box.innerHTML = `
                                    <div class="video-loading-placeholder">
                                        <div class="video-loading-spinner"></div>
                                        <div style="font-size:1.1rem; font-weight:600;">กำลังโหลดวิดีโอ...</div>
                                    </div>
                                `;
                            }
                        }, 200);
                    }
                }
                oldLayer.classList.remove('active');
            }

            // 2) Activate current layer
            const isVideo = item.media_type === 'youtube' || item.media_type === 'video';
            if (isVideo) {
                document.body.classList.add('video-active');
            } else {
                document.body.classList.remove('video-active');
            }

            if (newLayer) {
                newLayer.classList.add('active');

                // If Native MP4 Video
                if (item.media_type === 'video' || (item.embed_url && item.embed_url.match(/\.(mp4|webm|ogg)$/i))) {
                    const videoBox = newLayer.querySelector('.iframe-container');
                    if (videoBox) {
                        videoBox.innerHTML = `
                            <video id="native-video-active"
                                src="${item.embed_url}" 
                                autoplay 
                                ${item.enable_sound ? '' : 'muted'} 
                                loop 
                                playsinline
                                style="width:100%; height:100%; object-fit:contain; background:#000;">
                            </video>
                        `;
                    }
                }
                // If YouTube: Clean iframe mount with forced 720p lock
                else if (item.media_type === 'youtube') {
                    const ytBox = newLayer.querySelector('.iframe-container');
                    if (ytBox) {
                        ytBox.innerHTML = `
                            <iframe id="yt-player-active"
                                src="${item.embed_url}" 
                                allow="autoplay; encrypted-media; picture-in-picture" 
                                allowfullscreen
                                loading="eager"
                                style="width:100%; height:100%; border:0; display:block; background:#000;">
                            </iframe>
                        `;

                        const iframe = ytBox.querySelector('iframe');
                        if (iframe) {
                            iframe.onload = () => {
                                // Enforce 720p stream clamping for butter-smooth 60/30fps playback on Smart TV
                                postToYouTube(iframe, 'setPlaybackQuality', ['hd720']);
                                postToYouTube(iframe, 'setPlaybackRate', [1]);
                                if (item.enable_sound) {
                                    postToYouTube(iframe, 'unMute');
                                } else {
                                    postToYouTube(iframe, 'mute');
                                }
                                postToYouTube(iframe, 'playVideo');
                            };
                        }
                    }
                }
            }

            // 3) Pre-warm next slide if it's an image
            if (state.playlist.length > 1) {
                const nextIdx = (index + 1) % state.playlist.length;
                const nextItem = state.playlist[nextIdx];
                if (nextItem && nextItem.media_type === 'image' && nextItem.embed_url) {
                    const preImg = new Image();
                    preImg.src = nextItem.embed_url;
                }
            }

            setTimeout(() => {
                state.isTransitioning = false;
            }, 300);

            // 4) Start Hardware-Accelerated Progress Animation
            startSlideProgress(durationMs);
        }

        // 6. Progress Bar via CSS Transition (0% JavaScript timer overhead)
        function startSlideProgress(durationMs) {
            clearTimeout(state.slideTimer);
            const bar = dom.progressBar;
            if (!bar) return;

            // Reset width instantly without transition
            bar.style.transition = 'none';
            bar.style.width = '0%';
            void bar.offsetWidth; // Force synchronous reflow

            // Animate smoothly on compositor thread
            bar.style.transition = `width ${durationMs}ms linear`;
            bar.style.width = '100%';

            state.slideTimer = setTimeout(() => {
                nextSlide();
            }, durationMs);
        }

        function nextSlide() {
            if (state.playlist.length <= 1) {
                startSlideProgress((state.playlist[0]?.duration_seconds || 10) * 1000);
                return;
            }
            const nextIdx = (state.currentIndex + 1) % state.playlist.length;
            showSlide(nextIdx);
        }

        function prevSlide() {
            if (state.playlist.length <= 1) return;
            const prevIdx = (state.currentIndex - 1 + state.playlist.length) % state.playlist.length;
            showSlide(prevIdx);
        }

        // 7. Fullscreen & Interaction Controls
        function toggleFullscreen() {
            const doc = window.document;
            const docEl = doc.documentElement;

            const requestFS = docEl.requestFullscreen || docEl.webkitRequestFullScreen || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;
            const cancelFS = doc.exitFullscreen || doc.webkitExitFullscreen || doc.mozCancelFullScreen || doc.msExitFullscreen;
            const isFS = doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement;

            if (!isFS) {
                if (requestFS) requestFS.call(docEl).catch(() => {});
            } else {
                if (cancelFS) cancelFS.call(doc);
            }
        }

        function updateFsState() {
            const isFS = document.fullscreenElement || document.webkitFullscreenElement;
            if (dom.fsBtn) {
                dom.fsBtn.innerHTML = isFS
                    ? '<i class="bi bi-fullscreen-exit"></i><span>ย่อจอ</span>'
                    : '<i class="bi bi-arrows-fullscreen"></i><span>เต็มจอ</span>';
            }
            if (dom.fsHint) {
                dom.fsHint.style.display = isFS ? 'none' : 'flex';
            }
        }
        document.addEventListener('fullscreenchange', updateFsState);
        document.addEventListener('webkitfullscreenchange', updateFsState);

        // Unlock Browser Autoplay on First User Interaction
        let audioUnlocked = false;
        function unlockAudioOnGesture() {
            if (audioUnlocked) return;
            audioUnlocked = true;
            const activeLayer = document.querySelector('.slide-layer.active');
            if (activeLayer && activeLayer.getAttribute('data-type') === 'youtube') {
                const f = activeLayer.querySelector('iframe');
                if (f) {
                    postToYouTube(f, 'unMute');
                    postToYouTube(f, 'playVideo');
                }
            }
        }
        window.addEventListener('touchstart', unlockAudioOnGesture, { once: true, passive: true });
        window.addEventListener('click', unlockAudioOnGesture, { once: true });

        window.addEventListener('dblclick', toggleFullscreen);
        window.addEventListener('keydown', (e) => {
            if (e.key === 'f' || e.key === 'F') toggleFullscreen();
            if (e.key === 'ArrowRight' || e.key === ' ') nextSlide();
            if (e.key === 'ArrowLeft') prevSlide();
        });

        // 8. Auto-hide mouse cursor after 3 seconds
        let mouseTimer = null;
        window.addEventListener('mousemove', () => {
            document.body.classList.remove('hide-cursor');
            clearTimeout(mouseTimer);
            mouseTimer = setTimeout(() => {
                document.body.classList.add('hide-cursor');
            }, 3000);
        });

        // 9. Start Signage Engine
        fetchPlaylist();
        setInterval(fetchPlaylist, CONFIG.POLL_INTERVAL_MS);
    </script>
</body>
</html>
