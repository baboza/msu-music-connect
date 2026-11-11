<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>MSU Music Interactive Kiosk · Screen 2 (Touch Terminal)</title>
    
    <!-- Google Fonts: Prompt, Cinzel, Noto Sans SC & Sarabun -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Cinzel:wght@600;700;800&family=Noto+Sans+SC:wght@400;500;700&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --bg-deep: #070913;
            --bg-radial: radial-gradient(circle at 50% 15%, #151d36 0%, #0a0e1c 55%, #05070d 100%);
            --surface-glass: rgba(18, 25, 46, 0.72);
            --surface-card: rgba(22, 31, 56, 0.82);
            --surface-hover: rgba(30, 42, 75, 0.95);
            --gold-primary: #d4af37;
            --gold-light: #f7ebb8;
            --gold-gradient: linear-gradient(135deg, #b8860b 0%, #d4af37 50%, #f6e8b8 100%);
            --gold-glow: rgba(212, 175, 55, 0.4);
            --text-main: #ffffff;
            --text-sub: #cbd5e1;
            --text-muted: #94a3b8;
            --border-subtle: rgba(255, 255, 255, 0.09);
            --border-gold: rgba(212, 175, 55, 0.32);
            --cyan-accent: #38bdf8;
            --purple-accent: #a855f7;
            --navbar-height: 96px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        body, html {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: var(--bg-deep);
            font-family: 'Prompt', 'Noto Sans SC', 'Sarabun', sans-serif;
            color: var(--text-main);
            touch-action: manipulation;
            -webkit-text-size-adjust: 100%;
        }

        #kiosk-app {
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            background: var(--bg-radial);
            overflow: hidden;
            touch-action: manipulation;
        }

        /* Ambient Lighting Aura Behind Content - Optimized for 4K GPU fill-rate */
        .kiosk-ambient-glow {
            position: absolute;
            top: 5%;
            left: 50%;
            transform: translateX(-50%);
            width: 85vw;
            height: 45vh;
            background: radial-gradient(ellipse at center, rgba(212, 175, 55, 0.08) 0%, rgba(56, 189, 248, 0.03) 40%, transparent 70%);
            pointer-events: none;
            z-index: 1;
        }

        /* ---------------- TOP NAVBAR HUD ---------------- */
        #top-navbar {
            height: var(--navbar-height);
            padding: 0 clamp(16px, 2.2vw, 35px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(10, 14, 26, 0.98);
            border-bottom: 1.5px solid var(--border-gold);
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            gap: 12px;
        }

        .kiosk-brand {
            display: flex;
            align-items: center;
            gap: clamp(10px, 1.2vw, 18px);
            cursor: pointer;
            transition: transform 0.2s ease;
            min-width: 0;
            flex-shrink: 1;
        }

        .kiosk-brand:active {
            transform: scale(0.98);
        }

        .brand-logo-frame {
            width: clamp(48px, 4vw, 58px);
            height: clamp(48px, 4vw, 58px);
            flex-shrink: 0;
            border-radius: 16px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.22) 0%, rgba(15, 20, 36, 0.9) 100%);
            border: 1.5px solid var(--border-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.5), 0 0 16px rgba(212, 175, 55, 0.2);
        }

        .kiosk-logo {
            height: clamp(36px, 3vw, 44px);
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.7));
        }

        .brand-text {
            min-width: 0;
            overflow: hidden;
        }

        .brand-text h1 {
            font-size: clamp(0.95rem, 1.25vw, 1.35rem);
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 0.3px;
            line-height: 1.25;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin: 0;
        }

        .brand-text h1 .gold-word {
            background: linear-gradient(135deg, #ffd700 0%, #f6e8b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
        }

        .brand-text p {
            font-size: clamp(0.72rem, 0.8vw, 0.82rem);
            color: var(--text-muted);
            letter-spacing: 0.8px;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin: 2px 0 0 0;
        }

        /* Center Nav Controls: Home Button + Soundwave + Language Switcher */
        .nav-center-group {
            display: flex;
            align-items: center;
            gap: clamp(8px, 1.2vw, 16px);
            flex-shrink: 0;
        }

        .home-nav-btn {
            background: rgba(22, 31, 56, 0.85);
            border: 1.5px solid var(--border-gold);
            color: #ffffff;
            padding: 10px clamp(14px, 1.4vw, 22px);
            border-radius: 40px;
            font-size: clamp(0.9rem, 1vw, 1.05rem);
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.35);
        }

        .home-nav-btn:hover, .home-nav-btn:active {
            background: var(--gold-gradient);
            color: #07090e;
            border-color: #ffd700;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.45);
        }

        /* Soundwave Mini Indicator */
        .soundwave-indicator {
            display: flex;
            align-items: center;
            gap: 3px;
            height: 16px;
            padding: 0 6px;
            opacity: 0.85;
            flex-shrink: 0;
        }

        .sw-bar {
            width: 3px;
            border-radius: 3px;
            background: linear-gradient(to top, var(--gold-primary), var(--cyan-accent));
            animation: sw-wave 1.2s ease-in-out infinite alternate;
        }
        .sw-bar:nth-child(1) { height: 6px; animation-delay: 0.1s; }
        .sw-bar:nth-child(2) { height: 14px; animation-delay: 0.3s; }
        .sw-bar:nth-child(3) { height: 8px; animation-delay: 0.15s; }
        .sw-bar:nth-child(4) { height: 16px; animation-delay: 0.4s; }
        .sw-bar:nth-child(5) { height: 10px; animation-delay: 0.2s; }

        @keyframes sw-wave {
            0% { transform: scaleY(0.4); opacity: 0.5; }
            100% { transform: scaleY(1.3); opacity: 1; }
        }

        /* Segmented Language Switcher (National Flags Only) */
        .lang-switcher {
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(18, 25, 46, 0.9);
            border: 1px solid var(--border-gold);
            padding: 4px 6px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            flex-shrink: 0;
        }

        .lang-btn {
            background: transparent;
            border: none;
            padding: 6px 10px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            height: 38px;
            box-sizing: border-box;
            touch-action: manipulation;
        }

        .lang-btn:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .lang-btn.active {
            background: var(--gold-gradient);
            box-shadow: 0 3px 14px rgba(212, 175, 55, 0.5);
            transform: scale(1.06);
        }

        .lang-btn .flag-icon {
            width: 28px;
            height: 20px;
            border-radius: 4px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.45);
            display: block;
            pointer-events: none;
            flex-shrink: 0;
            overflow: hidden;
        }

        .lang-btn.active .flag-icon {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.7);
        }

        /* Touch Fullscreen Button on Topbar */
        .btn-fs-touch {
            background: rgba(22, 31, 56, 0.9);
            border: 1.5px solid var(--border-gold);
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.35);
            font-family: inherit;
            touch-action: manipulation;
        }

        .btn-fs-touch:hover, .btn-fs-touch:active {
            background: var(--gold-gradient);
            color: #07090e;
            border-color: #ffd700;
            transform: scale(1.04);
            box-shadow: 0 4px 18px rgba(212, 175, 55, 0.45);
        }

        .btn-fs-touch i {
            font-size: 1rem;
            color: var(--gold-light);
        }

        .btn-fs-touch:hover i, .btn-fs-touch:active i {
            color: #07090e;
        }

        /* Clock Box */
        .clock-box {
            text-align: right;
            border-left: 1px solid rgba(255, 255, 255, 0.12);
            padding-left: clamp(12px, 1.5vw, 22px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .clock-box .time {
            font-size: clamp(1.35rem, 1.6vw, 1.8rem);
            font-weight: 700;
            color: #ffffff;
            font-family: 'Cinzel', sans-serif;
            letter-spacing: 1.5px;
            line-height: 1.1;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8);
        }

        .clock-box .time .sec {
            font-size: 0.65em;
            color: var(--gold-light);
            opacity: 0.9;
        }

        .clock-box .date {
            font-size: clamp(0.72rem, 0.8vw, 0.82rem);
            color: #cbd5e1;
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        /* Topbar Responsive Media Queries: prevent squeezing and overflow */
        @media (max-width: 1450px) {
            .soundwave-indicator {
                display: none !important;
            }
            .lang-btn {
                padding: 5px 8px;
                min-width: 38px;
                height: 34px;
            }
            .lang-btn .flag-icon {
                width: 24px;
                height: 17px;
            }
        }

        @media (max-width: 1180px) {
            .brand-text p {
                display: none !important;
            }
            #ui-fs-label {
                display: none !important;
            }
            .btn-fs-touch {
                padding: 8px 12px;
            }
        }

        /* ---------------- MAIN CONTENT VIEWPORT (Hardware Touch Scroll Optimized) ---------------- */
        #content-viewport {
            flex: 1;
            position: relative;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 35px 40px 60px 40px;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            will-change: scroll-position;
            z-index: 10;
        }

        #content-viewport::-webkit-scrollbar {
            width: 8px;
        }
        #content-viewport::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.25);
        }
        #content-viewport::-webkit-scrollbar-thumb {
            background: var(--gold-primary);
            border-radius: 10px;
        }

        /* ---------------- HOME VIEW ---------------- */
        #home-view {
            max-width: 1680px;
            margin: 0 auto;
        }

        .home-banner {
            text-align: center;
            margin-bottom: 35px;
            position: relative;
        }

        .home-banner-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid rgba(212, 175, 55, 0.4);
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--gold-light);
            margin-bottom: 12px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .home-banner h2 {
            font-size: 2.35rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
            letter-spacing: 0.3px;
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.7);
        }

        .home-banner p {
            font-size: 1.12rem;
            color: var(--gold-light);
            font-weight: 400;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* 8 Category Grid (Always 4 or 3 cards per row, never 2) */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            max-width: 1680px;
            margin: 0 auto;
        }

        @media (max-width: 1150px) {
            .category-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
            }
        }

        .category-card {
            background: var(--surface-card);
            border: 1.5px solid var(--border-subtle);
            border-radius: 24px;
            padding: clamp(22px, 2.2vw, 32px) clamp(16px, 1.8vw, 24px) clamp(18px, 2vw, 24px);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        /* Dynamic Category Accent Bar */
        .category-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, transparent, var(--card-color, var(--gold-primary)), transparent);
            opacity: 0.6;
            transition: opacity 0.3s, height 0.3s;
        }

        .category-card:active, .category-card:hover {
            transform: translateY(-8px) scale(1.02);
            background: var(--surface-hover);
            border-color: var(--card-color, var(--gold-primary));
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 30px var(--card-glow, rgba(212, 175, 55, 0.3));
        }

        .category-card:hover::before {
            opacity: 1;
            height: 5px;
        }

        /* Category Icon Orb */
        .category-icon-wrapper {
            position: relative;
            width: clamp(72px, 5.5vw, 92px);
            height: clamp(72px, 5.5vw, 92px);
            border-radius: clamp(20px, 2vw, 28px);
            background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.15) 0%, rgba(13, 17, 30, 0.8) 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(2.2rem, 2.5vw, 2.8rem);
            color: var(--card-color, var(--gold-primary));
            margin-bottom: clamp(12px, 1.5vw, 20px);
            transition: transform 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4), 0 0 18px var(--card-glow, rgba(212, 175, 55, 0.2));
        }

        .category-card:hover .category-icon-wrapper {
            transform: scale(1.12) rotate(3deg);
            border-color: var(--card-color, var(--gold-primary));
        }

        .category-title {
            font-size: clamp(1.12rem, 1.2vw, 1.35rem);
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .category-subtext {
            font-size: clamp(0.78rem, 0.82vw, 0.86rem);
            color: var(--text-muted);
            line-height: 1.4;
            margin-bottom: clamp(12px, 1.4vw, 18px);
            flex: 1;
        }

        .category-footer-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.85rem;
            color: var(--card-color, var(--gold-primary));
            font-weight: 600;
        }

        .category-count-badge {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .category-action-link {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ---------------- DETAIL VIEW ---------------- */
        #detail-view {
            display: none;
            max-width: 1680px;
            margin: 0 auto;
        }

        .view-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            background: var(--surface-card);
            border: 1.5px solid var(--border-gold);
            padding: 20px 32px;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(20px);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 22px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1.5px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 12px 26px;
            border-radius: 40px;
            font-size: 1.08rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .back-btn:active, .back-btn:hover {
            background: var(--gold-gradient);
            color: #07090e;
            border-color: #ffd700;
            transform: translateX(-3px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.45);
        }

        .view-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .view-title i {
            color: var(--gold-primary);
            filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.5));
        }

        /* Search Bar & Touch Input */
        .search-container {
            position: relative;
            width: 400px;
        }

        .search-input {
            width: 100%;
            height: 52px;
            background: rgba(8, 12, 22, 0.7);
            border: 1.5px solid rgba(255, 255, 255, 0.18);
            border-radius: 30px;
            padding: 0 50px 0 24px;
            font-size: 1.02rem;
            color: #ffffff;
            outline: none;
            font-family: inherit;
            transition: all 0.25s ease;
        }

        .search-input:focus {
            border-color: var(--gold-primary);
            background: rgba(8, 12, 22, 0.95);
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.35);
        }

        .search-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold-light);
            font-size: 1.25rem;
            pointer-events: none;
        }

        @media (max-width: 1150px) {
            .view-header {
                padding: 14px 20px;
                margin-bottom: 20px;
                gap: 12px;
            }
            .header-left {
                gap: 14px;
            }
            .search-container {
                width: 260px;
            }
            .view-title {
                font-size: 1.4rem;
            }
            .back-btn {
                padding: 10px 18px;
                font-size: 0.95rem;
            }
        }

        /* ---------------- STAFF SUBNAV TABS & SECTIONS ---------------- */
        .staff-subnav-tabs {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 0 24px 0;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .staff-subnav-tabs::-webkit-scrollbar {
            display: none;
        }

        .staff-tab-btn {
            background: rgba(22, 31, 56, 0.85);
            border: 1.5px solid var(--border-gold);
            color: #cbd5e1;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
            font-family: inherit;
        }

        .staff-tab-btn:hover {
            background: rgba(212, 175, 55, 0.18);
            border-color: var(--gold-primary);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .staff-tab-btn.active {
            background: var(--gold-gradient);
            color: #07090e;
            border-color: #ffd700;
            font-weight: 700;
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.45);
            transform: translateY(-2px);
        }

        .staff-sections-wrapper {
            display: flex !important;
            flex-direction: column !important;
            gap: 38px !important;
        }

        .staff-section-block {
            width: 100%;
        }

        .staff-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 26px;
            background: linear-gradient(90deg, rgba(57, 4, 143, 0.85) 0%, rgba(22, 31, 56, 0.85) 100%);
            border-left: 5px solid var(--gold-primary);
            border-radius: 18px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
            border-top: 1px solid rgba(212, 175, 55, 0.25);
            border-right: 1px solid rgba(212, 175, 55, 0.12);
            border-bottom: 1px solid rgba(212, 175, 55, 0.12);
        }

        .staff-section-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .staff-section-count {
            background: rgba(212, 175, 55, 0.2);
            border: 1px solid rgba(212, 175, 55, 0.45);
            color: var(--gold-light);
            padding: 5px 16px;
            border-radius: 30px;
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* ---------------- STAFF CARDS & GRID (Always 4 or 3 cards per row, never 2) ---------------- */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        @media (max-width: 1350px) {
            .items-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
            }
        }

        .items-grid.staff-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding: 0 !important;
        }

        @media (max-width: 1350px) {
            .items-grid.staff-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
            }
        }

        .staff-card {
            background: var(--surface-card);
            border: 1.5px solid var(--border-gold);
            border-radius: 22px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
            position: relative;
            content-visibility: auto;
            contain-intrinsic-size: 260px 440px;
        }

        .staff-card:active {
            border-color: #ffd700;
        }

        .staff-thumb-box {
            position: relative;
            width: 100%;
            height: clamp(220px, 16vw, 290px);
            overflow: hidden;
            background: radial-gradient(circle at 50% 30%, #22143b 0%, #0d0617 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .staff-thumb-img {
            position: relative;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top center;
            z-index: 2;
        }

        .staff-card:hover .staff-thumb-img {
            transform: scale(1.05);
        }

        .staff-thumb-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 95px;
            background: linear-gradient(to top, var(--surface-card) 10%, rgba(20, 23, 36, 0.7) 50%, transparent 100%);
            z-index: 3;
            pointer-events: none;
        }

        .staff-role-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 4;
            padding: 7px 16px;
            border-radius: 30px;
            font-size: 0.84rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5);
        }

        .staff-role-badge.badge-academic {
            background: linear-gradient(135deg, #065f46 0%, #047857 50%, #059669 100%);
            color: #ffffff !important;
            border: 1.5px solid rgba(255, 255, 255, 0.55);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5), 0 0 14px rgba(16, 185, 129, 0.45);
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
        }

        .staff-role-badge.badge-support {
            background: linear-gradient(135deg, #9a3412 0%, #c2410c 50%, #d97706 100%);
            color: #ffffff !important;
            border: 1.5px solid rgba(255, 255, 255, 0.55);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.5), 0 0 14px rgba(234, 88, 12, 0.45);
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
        }

        .staff-card-body {
            padding: clamp(14px, 1.4vw, 20px) clamp(14px, 1.4vw, 20px) clamp(12px, 1.2vw, 16px);
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: var(--surface-card);
        }

        .staff-card-top {
            margin-bottom: 12px;
        }

        .staff-card-name {
            font-size: clamp(1.05rem, 1.15vw, 1.25rem);
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .staff-card-pos {
            font-size: clamp(0.85rem, 0.9vw, 0.95rem);
            font-weight: 600;
            color: #d4af37;
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .staff-card-dept {
            font-size: clamp(0.78rem, 0.82vw, 0.88rem);
            color: #cbd5e1;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            line-height: 1.3;
        }

        .staff-card-contact {
            font-size: clamp(0.75rem, 0.8vw, 0.82rem);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            line-height: 1.3;
        }

        .staff-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .staff-footer-btn {
            font-size: 0.85rem;
            font-weight: 700;
            color: #ffd86b;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.35);
            padding: 5px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            transition: all 0.25s ease;
        }

        .staff-card:hover .staff-footer-btn {
            background: rgba(212, 175, 55, 0.28);
            border-color: #ffd700;
            color: #ffffff;
            box-shadow: 0 2px 10px rgba(212, 175, 55, 0.35);
        }

        .staff-footer-qr {
            font-size: 0.78rem;
            font-weight: 600;
            color: #e2e8f0;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* ---------------- STANDARD ARTICLE CARDS ---------------- */
        .item-card {
            background: var(--surface-card);
            border: 1.5px solid var(--border-gold);
            border-radius: 20px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
            content-visibility: auto;
            contain-intrinsic-size: 260px 380px;
        }

        .item-card:active {
            border-color: #ffd700;
        }

        .item-thumb {
            width: 100%;
            height: clamp(160px, 12vw, 210px);
            object-fit: cover;
            background-color: #0b0f19;
            transition: transform 0.4s ease;
        }

        .item-card:hover .item-thumb {
            transform: scale(1.04);
        }

        .item-body {
            padding: clamp(14px, 1.4vw, 20px);
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .item-title {
            font-size: clamp(1.05rem, 1.15vw, 1.25rem);
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .item-snippet {
            font-size: clamp(0.82rem, 0.88vw, 0.92rem);
            color: var(--text-muted);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gold-light);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 12px;
        }

        /* ---------------- ITEM DETAIL MODAL ---------------- */
        #item-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(4, 6, 12, 0.88);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 30px;
        }

        .modal-box {
            background: rgba(15, 21, 38, 0.96);
            border: 1.5px solid var(--border-gold);
            border-radius: 28px;
            width: 100%;
            max-width: 1050px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.85), 0 0 40px rgba(212, 175, 55, 0.25);
            animation: modalPop 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
            transition: max-width 0.3s ease, width 0.3s ease, height 0.3s ease;
        }

        @keyframes modalPop {
            0% { transform: scale(0.92); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.65);
            border: 1.5px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 1.3rem;
            cursor: pointer;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }

        .modal-close-btn:hover {
            background: #dc2626;
            border-color: #f87171;
            transform: scale(1.1);
        }

        .modal-header-img {
            width: 100%;
            max-height: 380px;
            object-fit: cover;
            border-bottom: 2px solid var(--border-gold);
        }

        .modal-body {
            padding: 35px;
        }

        .modal-title {
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 14px;
            line-height: 1.3;
        }

        .modal-info-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 1rem;
            color: var(--gold-light);
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-desc {
            font-size: 1.08rem;
            line-height: 1.75;
            color: #e2e8f0;
            margin-bottom: 30px;
            white-space: pre-line;
        }

        /* Fullscreen In-Modal Document & Webpage Viewer (Same Modal) */
        .modal-box.viewer-mode {
            width: 95vw !important;
            max-width: 95vw !important;
            height: 90vh !important;
            max-height: 90vh !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            border-radius: 24px !important;
            background: #0b0f19 !important;
            border: 2px solid var(--border-gold) !important;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.95), 0 0 40px rgba(212, 175, 55, 0.3) !important;
        }

        .modal-box.viewer-mode .modal-close-btn {
            display: none !important;
        }

        .modal-box.viewer-mode #modal-content-area {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .inmodal-viewer-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: #090d17;
        }

        .inmodal-viewer-header {
            background: linear-gradient(90deg, #1f0238 0%, #11182c 100%);
            border-bottom: 2px solid var(--gold-primary);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .inmodal-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
            overflow: hidden;
        }

        .inmodal-back-btn {
            background: rgba(212, 175, 55, 0.2);
            border: 1.5px solid var(--gold-primary);
            color: #ffd86b;
            padding: 10px 22px;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .inmodal-back-btn:hover {
            background: var(--gold-primary);
            color: #07090e;
        }

        .inmodal-header-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .inmodal-close-btn {
            background: #dc3545;
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
            white-space: nowrap;
        }

        .inmodal-close-btn:hover {
            background: #b02a37;
        }

        .inmodal-iframe-wrapper {
            flex: 1;
            width: 100%;
            height: 100%;
            position: relative;
            background: #ffffff;
            overflow: hidden;
        }

        .inmodal-iframe-wrapper iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .inmodal-viewer-footer {
            background: #0f1526;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .inmodal-footer-hint {
            font-size: 0.88rem;
            color: #cbd5e1;
            display: flex;
            align-items: center;
        }

        .inmodal-back-btn-pill {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }

        /* ---------------- STAFF MODAL LAYOUT ---------------- */
        .staff-modal-container {
            display: flex;
            flex-direction: row;
            gap: 40px;
            padding: 35px;
            align-items: flex-start;
        }

        @media (max-width: 900px) {
            .staff-modal-container {
                flex-direction: column;
                align-items: center;
                padding: 25px;
            }
        }

        .staff-modal-photo-col {
            flex-shrink: 0;
            width: 330px;
        }

        .staff-modal-photo-card {
            position: relative;
            width: 330px;
            height: 420px;
            border-radius: 24px;
            overflow: hidden;
            border: 3px solid rgba(212, 175, 55, 0.5);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.7), 0 0 25px rgba(212, 175, 55, 0.25);
            background: radial-gradient(circle at 50% 30%, #301452 0%, #0d0617 100%);
        }

        .staff-modal-photo-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: top center;
        }

        .staff-modal-info-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .staff-modal-name {
            font-size: 1.95rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 6px;
            line-height: 1.25;
        }

        .staff-modal-subname {
            font-size: 1.15rem;
            color: #d4af37;
            font-weight: 600;
            margin-bottom: 18px;
        }

        .staff-modal-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .staff-modal-pill-role {
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
        }

        .staff-modal-pill-role.pill-academic {
            background: linear-gradient(135deg, #065f46 0%, #047857 50%, #059669 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.5);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4), 0 0 12px rgba(16, 185, 129, 0.35);
        }

        .staff-modal-pill-role.pill-support {
            background: linear-gradient(135deg, #9a3412 0%, #c2410c 50%, #d97706 100%);
            border: 1.5px solid rgba(255, 255, 255, 0.5);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4), 0 0 12px rgba(234, 88, 12, 0.35);
        }

        .staff-modal-pill-purple {
            background: rgba(147, 51, 234, 0.28);
            border: 1.5px solid rgba(168, 85, 247, 0.65);
            color: #f3e8ff;
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .staff-modal-details-table {
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 22px;
        }

        .staff-modal-detail-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 1rem;
            color: #e2e8f0;
        }

        .staff-modal-detail-row:last-child {
            margin-bottom: 0;
        }

        .staff-modal-detail-label {
            width: 130px;
            color: var(--text-muted);
            font-size: 0.92rem;
            flex-shrink: 0;
        }

        /* Action Buttons */
        .kiosk-action-btn {
            background: var(--gold-gradient);
            color: #07090e;
            border: none;
            padding: 14px 28px;
            border-radius: 40px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
            transition: all 0.25s ease;
            font-family: inherit;
        }

        .kiosk-action-btn:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.6);
        }

        /* QR Code Handover Card */
        .qr-section {
            background: rgba(0, 0, 0, 0.4);
            border: 1px dashed var(--gold-primary);
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 24px;
            margin-top: 20px;
        }

        .qr-box {
            width: 105px;
            height: 105px;
            background: #ffffff;
            border-radius: 14px;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            flex-shrink: 0;
        }

        .qr-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .qr-info h4 {
            font-size: 1.15rem;
            color: var(--gold-light);
            margin-bottom: 6px;
            font-weight: 700;
        }

        .qr-info p {
            font-size: 0.92rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* Empty State */
        .empty-box {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
        }

        .empty-box i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body>

    <div id="kiosk-app">
        <!-- Ambient Stage Glow Beam -->
        <div class="kiosk-ambient-glow"></div>

        <!-- Top Navbar -->
        <header id="top-navbar">
            <div class="kiosk-brand" onclick="goHome()">
                <div class="brand-logo-frame">
                    <img src="../../includes/logo.png" alt="Logo" class="kiosk-logo" onerror="this.style.display='none'">
                </div>
                <div class="brand-text">
                    <h1>วิทยาลัยดุริยางคศิลป์ <span class="gold-word">มหาวิทยาลัยมหาสารคาม</span></h1>
                    <p>College of Music, Mahasarakham University</p>
                </div>
            </div>

            <!-- Center Controls: Home Button + Soundwave + Language Switcher -->
            <div class="nav-center-group">
                <button type="button" class="home-nav-btn" onclick="goHome()" title="หน้าหลัก">
                    <i class="bi bi-house-door-fill text-gold"></i> <span id="ui-home">หน้าหลัก</span>
                </button>

                <!-- Music Soundwave Accent -->
                <div class="soundwave-indicator">
                    <div class="sw-bar"></div>
                    <div class="sw-bar"></div>
                    <div class="sw-bar"></div>
                    <div class="sw-bar"></div>
                    <div class="sw-bar"></div>
                </div>

                <!-- Language Switcher (National Flags Only: TH / EN / CN) -->
                <div class="lang-switcher" role="group" aria-label="เปลี่ยนภาษา / Language">
                    <button class="lang-btn active" data-lang="th" onclick="switchLanguage('th')" title="ภาษาไทย (TH)" aria-label="ภาษาไทย">
                        <svg class="flag-icon" viewBox="0 0 640 480" width="28" height="20">
                            <path fill="#f4f5f8" d="M0 0h640v480H0z"/>
                            <path fill="#2d2a4a" d="M0 160h640v160H0z"/>
                            <path fill="#a51931" d="M0 0h640v80H0zm0 400h640v80H0z"/>
                        </svg>
                    </button>
                    <button class="lang-btn" data-lang="en" onclick="switchLanguage('en')" title="English (EN)" aria-label="English">
                        <svg class="flag-icon" viewBox="0 0 60 30" width="28" height="20">
                            <clipPath id="uk-clip"><path d="M0,0 v30 h60 v-30 z"/></clipPath>
                            <clipPath id="uk-t"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                            <g clip-path="url(#uk-clip)">
                                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#uk-t)" stroke="#C8102E" stroke-width="4"/>
                                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                            </g>
                        </svg>
                    </button>
                    <button class="lang-btn" data-lang="cn" onclick="switchLanguage('cn')" title="中文 (CN)" aria-label="中文">
                        <svg class="flag-icon" viewBox="0 0 900 600" width="28" height="20">
                            <rect width="900" height="600" fill="#de2910"/>
                            <polygon fill="#ffde00" points="150,50 179,141 275,141 197,197 227,288 150,232 73,288 103,197 25,141 121,141"/>
                            <polygon fill="#ffde00" transform="translate(300,60) rotate(-120.96) scale(30)" points="0,-1 0.588,0.809 -0.951,-0.309 0.951,-0.309 -0.588,0.809"/>
                            <polygon fill="#ffde00" transform="translate(360,120) rotate(-98.13) scale(30)" points="0,-1 0.588,0.809 -0.951,-0.309 0.951,-0.309 -0.588,0.809"/>
                            <polygon fill="#ffde00" transform="translate(360,210) rotate(-74.05) scale(30)" points="0,-1 0.588,0.809 -0.951,-0.309 0.951,-0.309 -0.588,0.809"/>
                            <polygon fill="#ffde00" transform="translate(300,270) rotate(-51.34) scale(30)" points="0,-1 0.588,0.809 -0.951,-0.309 0.951,-0.309 -0.588,0.809"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Right Actions: Fullscreen Touch Button + Clock Box -->
            <div style="display: flex; align-items: center; gap: 16px;">
                <button type="button" class="btn-fs-touch" id="btn-fs-kiosk" onclick="toggleKioskFullscreen()" title="แตะเพื่อเปิด/ปิดจอเต็ม (Fullscreen)">
                    <i class="bi bi-arrows-fullscreen"></i>
                    <span id="ui-fs-label">เต็มจอ</span>
                </button>
                <div class="clock-box">
                    <div class="time" id="clock-time">--:--</div>
                    <div class="date" id="clock-date">-- --- ----</div>
                </div>
            </div>
        </header>

        <!-- Main Content Viewport -->
        <main id="content-viewport">
            
            <!-- HOME VIEW: 8 Categories Grid -->
            <div id="home-view">
                <div class="home-banner">
                    <div class="home-banner-badge">
                        <i class="bi bi-music-note-beamed"></i> Smart Interactive Touch Terminal
                    </div>
                    <h2 id="ui-welcome">ยินดีต้อนรับสู่วิทยาลัยดุริยางคศิลป์</h2>
                    <p id="ui-subtitle">
                        <i class="bi bi-hand-index-thumb"></i> สัมผัสหน้าจอเพื่อเลือกดูข้อมูล หรือสแกน QR Code เพื่ออ่านต่อบนสมาร์ตโฟน
                    </p>
                </div>

                <div class="category-grid" id="category-grid">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <!-- DETAIL VIEW: Items within selected category -->
            <div id="detail-view">
                <div class="view-header">
                    <div class="header-left">
                        <button class="back-btn" onclick="goHome()">
                            <i class="bi bi-arrow-left"></i> <span id="ui-back">ย้อนกลับ</span>
                        </button>
                        <h2 class="view-title" id="current-category-title">
                            <i class="bi bi-newspaper"></i> หมวดหมู่
                        </h2>
                    </div>

                    <div class="search-container">
                        <input type="text" class="search-input" id="search-input" placeholder="พิมพ์คำค้นหา..." oninput="handleSearch()">
                        <i class="bi bi-search search-icon"></i>
                    </div>
                </div>

                <!-- STAFF SUB-NAV TABS (Executives, Secretariat, Performance, Education) -->
                <div id="staff-subnav-container" class="staff-subnav-tabs" style="display: none;"></div>

                <div class="items-grid" id="items-grid">
                    <!-- Cards will be populated dynamically -->
                </div>
            </div>

        </main>

        <!-- ITEM DETAIL POPUP MODAL (Single Unified Kiosk Modal) -->
        <div id="item-modal" onclick="closeModal(event)">
            <div class="modal-box" id="item-modal-box" onclick="event.stopPropagation()">
                <button class="modal-close-btn" onclick="closeModal()" title="ปิดหน้าต่าง"><i class="bi bi-x-lg"></i></button>
                <!-- Dynamic Content Area -->
                <div id="modal-content-area"></div>
            </div>
        </div>

        <!-- FULLSCREEN IN-KIOSK CV VIEWER -->
        <div id="cv-viewer-modal" style="display: none;">
            <div class="cv-viewer-backdrop" onclick="closeCvViewer()"></div>
            <div class="cv-viewer-container">
                <div class="cv-viewer-header">
                    <div class="cv-viewer-title">
                        <i class="bi bi-file-earmark-person-fill text-gold me-2"></i>
                        <span id="cv-viewer-name">ประวัติบุคลากร (Curriculum Vitae)</span>
                    </div>
                    <div>
                        <button type="button" class="cv-btn-close" onclick="closeCvViewer()">
                            <i class="bi bi-x-lg me-1"></i> ปิดหน้าต่างประวัติ
                        </button>
                    </div>
                </div>
                <div class="cv-viewer-body">
                    <iframe id="cv-iframe" src="about:blank" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Next-Gen Smart Kiosk Terminal Controller (Screen 2)
         * - 8 Touch Interactive Categories with Live Counter Badges
         * - In-Modal Touch Document & Admission Viewer (Zero New Tabs)
         * - Multi-Lingual Architecture (TH / EN / CN)
         * - Intelligent Staff Directory with 4 Organizational Subsections
         * - Auto Idle Reset Watchdog (60s default)
         */
        const KIOSK = {
            API_URL: 'api/get_kiosk_data.php',
            LOG_API: 'api/log_interaction.php',
            currentLang: 'th',
            currentCategory: null,
            staffSection: 'all',
            categoriesData: {},
            allItems: [],
            uiData: {},
            idleSeconds: 60,
            idleCounter: 0,
            warningSeconds: 10,
            activeModalItem: null
        };

        // 1. Clock Widget (Cinzel font + Thai/International date)
        function updateClock() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clock-time').innerHTML = `${hours}:${minutes}<span class="sec">:${seconds}</span>`;

            const monthsTh = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
            const monthsEn = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

            if (KIOSK.currentLang === 'th') {
                const year = now.getFullYear() + 543;
                document.getElementById('clock-date').textContent = `${now.getDate()} ${monthsTh[now.getMonth()]} ${year}`;
            } else {
                document.getElementById('clock-date').textContent = `${now.getDate()} ${monthsEn[now.getMonth()]} ${now.getFullYear()}`;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 2. Fetch Data from API
        async function loadKioskData(lang = 'th', cat = 'all') {
            try {
                const res = await fetch(`${KIOSK.API_URL}?lang=${lang}&category=${cat}&t=${Date.now()}`);
                const data = await res.json();
                if (data.success) {
                    KIOSK.currentLang = data.current_lang;
                    KIOSK.categoriesData = data.categories;
                    KIOSK.uiData = data.ui;
                    KIOSK.allItems = data.items;
                    KIOSK.idleSeconds = data.idle_timeout || 60;

                    applyLanguageUI();
                    renderCategoryGrid();

                    if (KIOSK.currentCategory) {
                        renderItemsList(KIOSK.currentCategory);
                    }
                }
            } catch (err) {
                console.error('Failed to load kiosk data:', err);
            }
        }

        // 3. Switch Language
        function switchLanguage(lang) {
            resetIdleTimer();
            document.querySelectorAll('.lang-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.lang === lang);
            });
            loadKioskData(lang, KIOSK.currentCategory || 'all');
        }

        // 4. Apply UI Labels
        function applyLanguageUI() {
            const ui = KIOSK.uiData;
            if (!ui) return;
            const elWelcome = document.getElementById('ui-welcome');
            if (elWelcome) elWelcome.textContent = ui.welcome;
            const elSubtitle = document.getElementById('ui-subtitle');
            if (elSubtitle) {
                elSubtitle.innerHTML = `<i class="bi bi-hand-index-thumb"></i> ${ui.subtitle}`;
            }
            const elBack = document.getElementById('ui-back');
            if (elBack) elBack.textContent = ui.back;
            const elSearch = document.getElementById('search-input');
            if (elSearch) elSearch.placeholder = ui.search_placeholder;
            const elHome = document.getElementById('ui-home');
            if (elHome) elHome.textContent = ui.home || 'หน้าหลัก';

            if (KIOSK.currentCategory === 'staff') {
                renderStaffSubnav();
            }
        }

        // Staff Subnav Switching
        function switchStaffSection(secKey) {
            resetIdleTimer();
            KIOSK.staffSection = secKey;
            document.querySelectorAll('.staff-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.sec === secKey);
            });
            const searchVal = document.getElementById('search-input') ? document.getElementById('search-input').value : '';
            renderItemsList('staff', searchVal);
        }

        function renderStaffSubnav() {
            const container = document.getElementById('staff-subnav-container');
            if (!container) return;

            const labels = (KIOSK.uiData && KIOSK.uiData.staff_sections) ? KIOSK.uiData.staff_sections : {
                all: 'ทั้งหมด',
                executives: 'ผู้บริหาร',
                secretariat: 'สำนักงานเลขานุการ',
                music_perf: 'ภาควิชาการดุริยางคศิลป์',
                music_edu: 'ภาควิชาดนตรีศึกษา'
            };

            const tabs = [
                { key: 'all', label: labels.all || 'ทั้งหมด', icon: 'bi-grid-fill' },
                { key: 'executives', label: labels.executives || 'ผู้บริหาร', icon: 'bi-star-fill' },
                { key: 'secretariat', label: labels.secretariat || 'สำนักงานเลขานุการ', icon: 'bi-building' },
                { key: 'music_perf', label: labels.music_perf || 'ภาควิชาการดุริยางคศิลป์', icon: 'bi-music-note-beamed' },
                { key: 'music_edu', label: labels.music_edu || 'ภาควิชาดนตรีศึกษา', icon: 'bi-mortarboard-fill' }
            ];

            container.innerHTML = tabs.map(t => {
                // Calculate item count in this tab
                let count = 0;
                if (t.key === 'all') {
                    count = KIOSK.allItems.filter(i => i.category_key === 'staff').length;
                } else if (t.key === 'executives') {
                    count = KIOSK.allItems.filter(i => i.category_key === 'staff' && i.is_executive === true).length;
                } else {
                    count = KIOSK.allItems.filter(i => i.category_key === 'staff' && i.dept_key === t.key).length;
                }

                return `
                    <button type="button" class="staff-tab-btn ${KIOSK.staffSection === t.key ? 'active' : ''}" data-sec="${t.key}" onclick="switchStaffSection('${t.key}')">
                        <i class="bi ${t.icon}"></i> 
                        <span>${t.label}</span>
                        <span class="badge bg-dark text-gold rounded-pill ms-1" style="font-size:0.8rem;">${count}</span>
                    </button>
                `;
            }).join('');
        }

        // 5. Render 8 Category Cards with Live Counts
        function renderCategoryGrid() {
            const container = document.getElementById('category-grid');
            container.innerHTML = '';

            const cats = KIOSK.categoriesData;
            for (const key in cats) {
                const cat = cats[key];
                const card = document.createElement('div');
                card.className = 'category-card';
                card.style.setProperty('--card-color', cat.color || '#d4af37');
                card.style.setProperty('--card-glow', `${cat.color || '#d4af37'}55`);
                card.onclick = () => openCategory(key);

                // Count items in this category
                const itemCount = KIOSK.allItems.filter(i => i.category_key === key).length;
                const countUnit = (KIOSK.currentLang === 'cn') ? '条内容' : ((KIOSK.currentLang === 'en') ? 'items' : 'รายการ');

                const exploreText = (KIOSK.currentLang === 'cn') ? '轻触进入' : ((KIOSK.currentLang === 'en') ? 'Explore' : 'แตะเพื่อเข้าชม');

                card.innerHTML = `
                    <div class="category-icon-wrapper">
                        <i class="bi ${cat.icon}"></i>
                    </div>
                    <div class="category-title">${cat['title_' + KIOSK.currentLang] || cat.title_th}</div>
                    <div class="category-subtext">${KIOSK.uiData.touch_to_explore || 'แตะเพื่อเลือกดูข้อมูล'}</div>
                    <div class="category-footer-pill">
                        <span class="category-count-badge">${itemCount} ${countUnit}</span>
                        <span class="category-action-link">${exploreText} <i class="bi bi-chevron-right"></i></span>
                    </div>
                `;
                container.appendChild(card);
            }
        }

        // 6. Open Category Detail View
        function openCategory(catKey) {
            resetIdleTimer();
            KIOSK.currentCategory = catKey;
            KIOSK.staffSection = 'all';
            const cat = KIOSK.categoriesData[catKey];

            document.getElementById('home-view').style.display = 'none';
            document.getElementById('detail-view').style.display = 'block';

            const title = cat ? (cat['title_' + KIOSK.currentLang] || cat.title_th) : '';
            const icon = cat ? cat.icon : 'bi-folder';
            document.getElementById('current-category-title').innerHTML = `<i class="bi ${icon}"></i> ${title}`;
            document.getElementById('search-input').value = '';

            const subnav = document.getElementById('staff-subnav-container');
            if (catKey === 'staff') {
                if (subnav) {
                    subnav.style.display = 'flex';
                    renderStaffSubnav();
                }
            } else {
                if (subnav) subnav.style.display = 'none';
            }

            renderItemsList(catKey);
        }

        // 7. Go Back to Home View
        function goHome() {
            resetIdleTimer();
            KIOSK.currentCategory = null;
            KIOSK.staffSection = 'all';
            document.getElementById('detail-view').style.display = 'none';
            document.getElementById('home-view').style.display = 'block';
            const subnav = document.getElementById('staff-subnav-container');
            if (subnav) subnav.style.display = 'none';
            closeCvViewer();
            closeModal();
            renderCategoryGrid();
        }

        // Helper: Parse Staff Detail String
        function parseStaffDetail(detailText, locationFallback) {
            let type = 'academic';
            let typeLabel = (KIOSK.currentLang === 'cn') ? '教学科研人员（学术岗）' : 
                            ((KIOSK.currentLang === 'en') ? 'Academic Faculty' : 'บุคลากรสายวิชาการ');
            if (detailText && (detailText.includes('สายสนับสนุน') || detailText.includes('Support') || detailText.includes('行政支持'))) {
                type = 'support';
                typeLabel = (KIOSK.currentLang === 'cn') ? '行政支持人员（管理岗）' : 
                            ((KIOSK.currentLang === 'en') ? 'Support Personnel' : 'บุคลากรสายสนับสนุน');
            }

            let position = '';
            const posMatch = (detailText || '').match(/(?:ตำแหน่ง|Position|职务):\s*([^\n]+)/i);
            if (posMatch) position = posMatch[1].trim();

            let dept = locationFallback || '';
            const deptMatch = (detailText || '').match(/(?:สังกัด|Department|部门):\s*([^\n]+)/i);
            if (deptMatch) dept = deptMatch[1].trim();

            let email = '';
            const emailMatch = (detailText || '').match(/(?:อีเมล|Email|邮箱):\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i);
            if (emailMatch) email = emailMatch[1].trim();

            let phone = '';
            const phoneMatch = (detailText || '').match(/(?:โทรศัพท์|Tel|电话):\s*([0-9\s-]+)/i);
            if (phoneMatch) phone = phoneMatch[1].trim();

            return { type, typeLabel, position, dept, email, phone };
        }

        function createStaffCard(item) {
            const card = document.createElement('div');
            card.className = 'staff-card';
            card.onclick = () => openItemModal(item);

            const staff = parseStaffDetail(item.detail, item.location);
            const defaultAvatar = '../../assets/img/default-avatar.svg';
            const photoSrc = item.thumbnail || defaultAvatar;

            const cvBtnLabel = (KIOSK.currentLang === 'cn') ? '查阅简历 (CV)' : ((KIOSK.currentLang === 'en') ? 'View CV' : 'แตะดูประวัติ (CV)');
            const qrBtnLabel = (KIOSK.currentLang === 'cn') ? '扫码' : ((KIOSK.currentLang === 'en') ? 'Scan QR' : 'สแกน QR');

            card.innerHTML = `
                <div class="staff-thumb-box">
                    <img src="${photoSrc}" alt="${item.title}" class="staff-thumb-img" loading="lazy" decoding="async" onerror="this.onerror=null; this.src='${defaultAvatar}';">
                    <div class="staff-thumb-overlay"></div>
                    <span class="staff-role-badge ${staff.type === 'academic' ? 'badge-academic' : 'badge-support'}">
                        <i class="bi ${staff.type === 'academic' ? 'bi-mortarboard-fill' : 'bi-briefcase-fill'}"></i> ${staff.typeLabel}
                    </span>
                </div>
                <div class="staff-card-body">
                    <div class="staff-card-top">
                        <div class="staff-card-name">${item.title}</div>
                        <div class="staff-card-pos">${staff.position || (staff.type === 'academic' ? 'อาจารย์' : 'เจ้าหน้าที่')}</div>
                        <div class="staff-card-dept"><i class="bi bi-geo-alt-fill text-gold me-1"></i> ${staff.dept || 'วิทยาลัยดุริยางคศิลป์'}</div>
                        ${staff.email ? `<div class="staff-card-contact"><i class="bi bi-envelope-fill me-1 text-gold"></i> ${staff.email}</div>` : ''}
                    </div>
                    <div class="staff-card-footer">
                        <span class="staff-footer-btn">
                            <i class="bi bi-file-earmark-person-fill text-gold me-1"></i> ${cvBtnLabel}
                        </span>
                        <span class="staff-footer-qr"><i class="bi bi-qr-code-scan"></i> ${qrBtnLabel}</span>
                    </div>
                </div>
            `;
            return card;
        }

        // 8. Render Items List
        function renderItemsList(catKey, filterText = '') {
            const container = document.getElementById('items-grid');
            container.innerHTML = '';

            const subnav = document.getElementById('staff-subnav-container');

            if (catKey === 'staff') {
                container.className = 'staff-sections-wrapper';
                if (subnav) {
                    subnav.style.display = 'flex';
                    renderStaffSubnav();
                }

                const labels = (KIOSK.uiData && KIOSK.uiData.staff_sections) ? KIOSK.uiData.staff_sections : {};

                const sectionDefs = [
                    {
                        key: 'executives',
                        title: labels.executives || 'คณะผู้บริหาร',
                        icon: 'bi-star-fill',
                        filter: item => item.is_executive === true
                    },
                    {
                        key: 'secretariat',
                        title: labels.secretariat || 'สำนักงานเลขานุการ',
                        icon: 'bi-building',
                        filter: item => item.dept_key === 'secretariat'
                    },
                    {
                        key: 'music_perf',
                        title: labels.music_perf || 'ภาควิชาการดุริยางคศิลป์',
                        icon: 'bi-music-note-beamed',
                        filter: item => item.dept_key === 'music_perf'
                    },
                    {
                        key: 'music_edu',
                        title: labels.music_edu || 'ภาควิชาดนตรีศึกษา',
                        icon: 'bi-mortarboard-fill',
                        filter: item => item.dept_key === 'music_edu'
                    }
                ];

                const activeDefs = (KIOSK.staffSection === 'all') 
                    ? sectionDefs 
                    : sectionDefs.filter(s => s.key === KIOSK.staffSection);

                const q = filterText.trim().toLowerCase();
                let totalRendered = 0;

                activeDefs.forEach(sec => {
                    let secItems = KIOSK.allItems.filter(item => item.category_key === 'staff' && sec.filter(item));

                    if (q) {
                        secItems = secItems.filter(item => 
                            (item.title && item.title.toLowerCase().includes(q)) || 
                            (item.detail && item.detail.toLowerCase().includes(q)) ||
                            (item.location && item.location.toLowerCase().includes(q))
                        );
                    }

                    if (secItems.length > 0) {
                        totalRendered += secItems.length;

                        const secBlock = document.createElement('div');
                        secBlock.className = 'staff-section-block';
                        secBlock.id = `staff-sec-${sec.key}`;

                        const countUnit = (KIOSK.currentLang === 'cn') ? '位' : ((KIOSK.currentLang === 'en') ? 'members' : 'ท่าน');

                        const secHeader = document.createElement('div');
                        secHeader.className = 'staff-section-header';
                        secHeader.innerHTML = `
                            <div class="staff-section-title">
                                <i class="bi ${sec.icon} text-gold"></i>
                                <span>${sec.title}</span>
                            </div>
                            <span class="staff-section-count">${secItems.length} ${countUnit}</span>
                        `;
                        secBlock.appendChild(secHeader);

                        const grid = document.createElement('div');
                        grid.className = 'items-grid staff-grid';
                        secItems.forEach(item => {
                            grid.appendChild(createStaffCard(item));
                        });
                        secBlock.appendChild(grid);

                        container.appendChild(secBlock);
                    }
                });

                if (totalRendered === 0) {
                    container.innerHTML = `
                        <div class="empty-box">
                            <i class="bi bi-inbox"></i>
                            <h3>${KIOSK.uiData.no_data || 'ยังไม่พบข้อมูลตามคำค้นหา'}</h3>
                        </div>
                    `;
                }
                return;
            }

            // Normal Non-Staff Category
            container.className = 'items-grid';
            if (subnav) subnav.style.display = 'none';

            let filtered = KIOSK.allItems.filter(item => item.category_key === catKey);
            if (filterText.trim()) {
                const q = filterText.toLowerCase();
                filtered = filtered.filter(item => 
                    (item.title && item.title.toLowerCase().includes(q)) || 
                    (item.detail && item.detail.toLowerCase().includes(q)) ||
                    (item.location && item.location.toLowerCase().includes(q))
                );
            }

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="empty-box">
                        <i class="bi bi-inbox"></i>
                        <h3>${KIOSK.uiData.no_data || 'ยังไม่มีข้อมูลในหมวดนี้'}</h3>
                    </div>
                `;
                return;
            }

            filtered.forEach(item => {
                const card = document.createElement('div');
                card.className = 'item-card';
                card.onclick = () => openItemModal(item);

                const thumb = item.thumbnail || 'https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?w=600&fit=crop';
                const dateText = item.event_date ? `<i class="bi bi-calendar-event"></i> ${item.event_date}` : '';
                const locText = item.location ? `<i class="bi bi-geo-alt"></i> ${item.location}` : '';

                card.innerHTML = `
                    <img src="${thumb}" alt="${item.title}" class="item-thumb" loading="lazy" decoding="async" onerror="this.src='https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?w=600&fit=crop'">
                    <div class="item-body">
                        <div>
                            <div class="item-title">${item.title}</div>
                            <div class="item-snippet">${item.detail || ''}</div>
                        </div>
                        <div class="item-meta">
                            <span>${dateText || locText || '<i class="bi bi-eye"></i> ' + (item.view_count || 1) + ' views'}</span>
                            <span style="font-weight:700;"><i class="bi bi-arrow-right-circle"></i> ${KIOSK.uiData.details || 'รายละเอียด'}</span>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        // 9. Search within category
        function handleSearch() {
            resetIdleTimer();
            const text = document.getElementById('search-input').value;
            if (KIOSK.currentCategory) {
                renderItemsList(KIOSK.currentCategory, text);
            }
        }

        // 10. Open Detail Modal with QR Code (Single Unified Kiosk Modal)
        function openItemModal(item) {
            resetIdleTimer();
            KIOSK.activeModalItem = item;

            fetch(KIOSK.LOG_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `content_id=${item.content_id}`
            }).catch(() => {});

            const modalBox = document.getElementById('item-modal-box');
            if (modalBox) modalBox.classList.remove('viewer-mode');

            renderModalOverview(item);
            document.getElementById('item-modal').style.display = 'flex';
        }

        // Render Overview Card inside #item-modal
        function renderModalOverview(item) {
            const modalContentArea = document.getElementById('modal-content-area');
            const isStaff = (item.category_key === 'staff' || (item.qr_link && item.qr_link.includes('public_cv.php')));
            const qrTarget = item.qr_link || window.location.href;
            const qrApi = `https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=${encodeURIComponent(qrTarget)}`;
            const escapedTitle = (item.title || '').replace(/'/g, "\\'");

            if (isStaff) {
                const staff = parseStaffDetail(item.detail, item.location);
                const defaultAvatar = '../../assets/img/default-avatar.svg';
                const photoSrc = item.thumbnail || defaultAvatar;

                const btnLabel = (KIOSK.currentLang === 'cn') ? '轻触查阅个人完整简历 (View Full CV)' : 
                                 ((KIOSK.currentLang === 'en') ? 'Tap to View Full Curriculum Vitae (CV)' : 'แตะเพื่อเปิดดูประวัติฉบับเต็ม (View Full CV)');

                const qrHeader = (KIOSK.currentLang === 'cn') ? '扫描二维码在手机上阅读' : 
                                 ((KIOSK.currentLang === 'en') ? 'Scan QR Code for Mobile CV' : 'สแกน QR เพื่อเปิดอ่านประวัติบนมือถือ');
                const qrHint = (KIOSK.currentLang === 'cn') ? '使用智能手机相机扫描以查看教师完整学术档案' : 
                               ((KIOSK.currentLang === 'en') ? 'Scan with your smartphone camera to read complete profile on mobile' : 'สแกนผ่านกล้องสมาร์ตโฟนเพื่อเปิดอ่านรายละเอียดและผลงานบนมือถือของคุณ');

                modalContentArea.innerHTML = `
                    <div class="staff-modal-container">
                        <div class="staff-modal-photo-col">
                            <div class="staff-modal-photo-card">
                                <img src="${photoSrc}" alt="${item.title}" onerror="this.onerror=null; this.src='${defaultAvatar}';">
                            </div>
                        </div>
                        <div class="staff-modal-info-col">
                            <h2 class="staff-modal-name">${item.title}</h2>
                            <div class="staff-modal-subname">${staff.position || 'บุคลากรวิทยาลัยดุริยางคศิลป์'}</div>
                            
                            <div class="staff-modal-badges">
                                <span class="staff-modal-pill-role ${staff.type === 'academic' ? 'pill-academic' : 'pill-support'}">
                                    <i class="bi ${staff.type === 'academic' ? 'bi-mortarboard-fill' : 'bi-briefcase-fill'} me-1"></i> ${staff.typeLabel}
                                </span>
                                <span class="staff-modal-pill-purple">
                                    <i class="bi bi-geo-alt-fill me-1"></i> ${staff.dept || 'วิทยาลัยดุริยางคศิลป์'}
                                </span>
                            </div>

                            <div class="staff-modal-details-table">
                                <div class="staff-modal-detail-row">
                                    <span class="staff-modal-detail-label"><i class="bi bi-building me-1 text-gold"></i> สังกัดหน่วยงาน:</span>
                                    <span>${staff.dept || 'วิทยาลัยดุริยางคศิลป์'}</span>
                                </div>
                                ${staff.position ? `
                                <div class="staff-modal-detail-row">
                                    <span class="staff-modal-detail-label"><i class="bi bi-person-badge me-1 text-gold"></i> ตำแหน่ง:</span>
                                    <span>${staff.position}</span>
                                </div>` : ''}
                                ${staff.email ? `
                                <div class="staff-modal-detail-row">
                                    <span class="staff-modal-detail-label"><i class="bi bi-envelope me-1 text-gold"></i> อีเมลติดต่อ:</span>
                                    <span>${staff.email}</span>
                                </div>` : ''}
                                ${staff.phone ? `
                                <div class="staff-modal-detail-row">
                                    <span class="staff-modal-detail-label"><i class="bi bi-telephone me-1 text-gold"></i> เบอร์โทรศัพท์:</span>
                                    <span>${staff.phone}</span>
                                </div>` : ''}
                            </div>

                            <!-- Action Button: Open Full CV in SAME modal -->
                            <div class="mb-4">
                                <button type="button" class="kiosk-action-btn w-100" onclick="openInModalDoc('${item.qr_link}', '${escapedTitle}', 'cv')">
                                    <i class="bi bi-file-earmark-person-fill me-2"></i> ${btnLabel} <i class="bi bi-arrow-right-circle ms-1"></i>
                                </button>
                            </div>

                            <!-- QR Code Handover -->
                            <div class="qr-section">
                                <div class="qr-box">
                                    <img src="${qrApi}" alt="QR Code">
                                </div>
                                <div class="qr-info">
                                    <h4>${qrHeader}</h4>
                                    <p>${qrHint}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Classic Article / Poster / Curriculum Layout
                let metaHtml = '';
                if (item.event_date) metaHtml += `<div><i class="bi bi-calendar-event"></i> ${KIOSK.uiData.event_date || 'วันที่'}: ${item.event_date}</div>`;
                if (item.location) metaHtml += `<div><i class="bi bi-geo-alt"></i> ${KIOSK.uiData.location || 'สถานที่'}: ${item.location}</div>`;

                const detailsBtnText = (KIOSK.currentLang === 'cn') ? '查看详细资料 / 打开文档' : 
                                      ((KIOSK.currentLang === 'en') ? 'Read Full Details / Open Document' : (KIOSK.uiData.details || 'รายละเอียดเพิ่มเติม'));

                const actionBtnHtml = item.qr_link ? `
                    <div class="mt-4 mb-3 text-center">
                        <button type="button" class="kiosk-action-btn" onclick="openInModalDoc('${item.qr_link}', '${escapedTitle}', 'doc')">
                            <i class="bi bi-file-earmark-text-fill me-2"></i> ${detailsBtnText}
                        </button>
                    </div>
                ` : '';

                const formattedDesc = formatDescLinks(item.detail || '');

                modalContentArea.innerHTML = `
                    <img src="${item.thumbnail || 'https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?w=1000&fit=crop'}" alt="${item.title}" class="modal-header-img" onerror="this.src='https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?w=1000&fit=crop';">
                    <div class="modal-body">
                        <h2 class="modal-title">${item.title}</h2>
                        <div class="modal-info-row">${metaHtml}</div>
                        <div class="modal-desc">${formattedDesc}</div>
                        ${actionBtnHtml}
                        <div class="qr-section">
                            <div class="qr-box">
                                <img src="${qrApi}" alt="QR Code">
                            </div>
                            <div class="qr-info">
                                <h4>${KIOSK.uiData.scan_qr || 'สแกน QR เพื่อดูข้อมูลเพิ่มเติม'}</h4>
                                <p>สแกนผ่านกล้องสมาร์ตโฟนเพื่อเปิดอ่านรายละเอียดบนมือถือของคุณ</p>
                            </div>
                        </div>
                    </div>
                `;
            }
        }

        // Open Document / Webpage inside the SAME Modal (#item-modal) - Prevents new tab
        function openInModalDoc(url, title, type = 'doc') {
            resetIdleTimer();
            const modalBox = document.getElementById('item-modal-box');
            if (modalBox) modalBox.classList.add('viewer-mode');

            let viewerUrl = url;
            if (/\.(docx|doc)(\?.*)?$/i.test(url)) {
                viewerUrl = `https://view.officeapps.live.com/op/view.aspx?src=${encodeURIComponent(url)}`;
            }

            const isCv = (type === 'cv' || url.includes('public_cv.php'));
            const iconClass = isCv ? 'bi-file-earmark-person-fill' : 'bi-file-earmark-text-fill';
            const backLabel = (KIOSK.currentLang === 'cn') ? '返回简介' : 
                              ((KIOSK.currentLang === 'en') ? 'Back to Overview' : 'ย้อนกลับ');
            const closeLabel = (KIOSK.currentLang === 'cn') ? '关闭' : 
                               ((KIOSK.currentLang === 'en') ? 'Close' : 'ปิดหน้าต่าง');
            const hintText = (KIOSK.currentLang === 'cn') ? '轻触并滑动屏幕阅读文档，或扫描二维码使用手机查看' : 
                             ((KIOSK.currentLang === 'en') ? 'Scroll on screen to read document or scan QR code on mobile' : 'เลื่อนหน้าจอสัมผัสเพื่ออ่านเอกสาร หรือสแกน QR Code เพื่อเปิดอ่านบนโทรศัพท์มือถือ');

            const contentArea = document.getElementById('modal-content-area');
            contentArea.innerHTML = `
                <div class="inmodal-viewer-container">
                    <div class="inmodal-viewer-header">
                        <div class="inmodal-header-left">
                            <button type="button" class="inmodal-back-btn" onclick="restoreModalOverview()">
                                <i class="bi bi-arrow-left me-1"></i> ${backLabel}
                            </button>
                            <div class="inmodal-header-title">
                                <i class="bi ${iconClass} text-gold me-2"></i>
                                <span>${title}</span>
                            </div>
                        </div>
                        <button type="button" class="inmodal-close-btn" onclick="closeModal()">
                            <i class="bi bi-x-lg me-1"></i> ${closeLabel}
                        </button>
                    </div>
                    <div class="inmodal-iframe-wrapper">
                        <iframe id="inmodal-iframe" src="${viewerUrl}" frameborder="0"></iframe>
                    </div>
                    <div class="inmodal-viewer-footer">
                        <div class="inmodal-footer-hint">
                            <i class="bi bi-hand-index-thumb-fill text-gold me-1"></i> ${hintText}
                        </div>
                        <button type="button" class="inmodal-back-btn-pill" onclick="restoreModalOverview()">
                            <i class="bi bi-arrow-left me-1"></i> ${backLabel}
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('item-modal').style.display = 'flex';
        }

        // Restore Modal Overview from Document Viewer
        function restoreModalOverview() {
            resetIdleTimer();
            const modalBox = document.getElementById('item-modal-box');
            if (modalBox) modalBox.classList.remove('viewer-mode');
            const iframe = document.getElementById('inmodal-iframe');
            if (iframe) iframe.src = 'about:blank';
            if (KIOSK.activeModalItem) {
                renderModalOverview(KIOSK.activeModalItem);
            }
        }

        function closeModal(event) {
            const modalBox = document.getElementById('item-modal-box');
            if (modalBox) modalBox.classList.remove('viewer-mode');
            const iframe = document.getElementById('inmodal-iframe');
            if (iframe) iframe.src = 'about:blank';
            document.getElementById('item-modal').style.display = 'none';
        }

        // Helper: Convert URLs in description to in-modal clickable links
        function formatDescLinks(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            let safe = div.innerHTML;
            return safe.replace(/(https?:\/\/[^\s<]+)/g, function(url) {
                const cleanUrl = url.replace(/[.,;:)\]]+$/, '');
                const escapedUrl = cleanUrl.replace(/'/g, "\\'");
                return `<a href="javascript:void(0)" onclick="openInModalDoc('${escapedUrl}', '${escapedUrl}')" class="text-gold text-decoration-underline fw-bold" style="word-break: break-all;"><i class="bi bi-box-arrow-up-right me-1"></i>${cleanUrl}</a>`;
            });
        }

        // Backward-compatible delegates
        function openDocViewer(url, title, icon = 'bi-file-earmark-text-fill', closeLabel = '') {
            openInModalDoc(url, title, 'doc');
        }

        function openCvViewer(url, title) {
            openInModalDoc(url, `ประวัติบุคลากร: ${title}`, 'cv');
        }

        function closeCvViewer() {
            closeModal();
        }

        // 11. Idle Watchdog (Auto-Return quietly to Home after idle timeout, no warning toast)
        let lastTouchReset = 0;
        function resetIdleTimer() {
            KIOSK.idleCounter = 0;
        }

        function throttledResetIdleTimer() {
            const now = Date.now();
            if (now - lastTouchReset > 1500) {
                lastTouchReset = now;
                KIOSK.idleCounter = 0;
            }
        }

        ['click', 'touchstart'].forEach(evt => {
            window.addEventListener(evt, throttledResetIdleTimer, {passive: true});
        });

        setInterval(() => {
            KIOSK.idleCounter++;

            // เมื่อไม่มีการใช้งานเกินเวลาที่กำหนด ให้กลับสู่หน้าหลักอย่างเงียบๆ โดยไม่แสดงแจ้งเตือน
            if (KIOSK.idleCounter >= KIOSK.idleSeconds) {
                resetIdleTimer();
                closeCvViewer();
                closeModal();
                goHome();
                const searchInput = document.getElementById('search-input');
                if (searchInput) searchInput.value = '';
                const viewport = document.getElementById('content-viewport');
                if (viewport) viewport.scrollTop = 0;
                if (KIOSK.currentLang !== 'th') {
                    switchLanguage('th');
                }
            }
        }, 1000);

        // 12. Fullscreen Toggle (Touch Button, F Key, or Double Click Topbar)
        function toggleKioskFullscreen() {
            const doc = window.document;
            const docEl = doc.documentElement;

            const requestFullScreen = docEl.requestFullscreen || docEl.mozRequestFullScreen || docEl.webkitRequestFullScreen || docEl.msRequestFullscreen;
            const cancelFullScreen = doc.exitFullscreen || doc.mozCancelFullScreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
            const isFullscreen = doc.fullscreenElement || doc.mozFullScreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement;

            if (!isFullscreen) {
                if (requestFullScreen) requestFullScreen.call(docEl).catch(() => {});
            } else {
                if (cancelFullScreen) cancelFullScreen.call(doc);
            }
        }

        function updateKioskFsState() {
            const isFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
            const btn = document.getElementById('btn-fs-kiosk');
            if (btn) {
                let label = 'เต็มจอ';
                if (isFullscreen) {
                    label = (KIOSK.currentLang === 'en') ? 'Exit' : (KIOSK.currentLang === 'cn' ? '退出' : 'ย่อจอ');
                    btn.innerHTML = `<i class="bi bi-fullscreen-exit"></i><span id="ui-fs-label">${label}</span>`;
                } else {
                    label = (KIOSK.currentLang === 'en') ? 'Fullscreen' : (KIOSK.currentLang === 'cn' ? '全屏' : 'เต็มจอ');
                    btn.innerHTML = `<i class="bi bi-arrows-fullscreen"></i><span id="ui-fs-label">${label}</span>`;
                }
            }
        }
        document.addEventListener('fullscreenchange', updateKioskFsState);
        document.addEventListener('webkitfullscreenchange', updateKioskFsState);

        document.getElementById('top-navbar').addEventListener('dblclick', toggleKioskFullscreen);

        window.addEventListener('keydown', (e) => {
            if (e.key === 'f' || e.key === 'F') {
                toggleKioskFullscreen();
            }
        });

        // Initialize Kiosk
        loadKioskData('th', 'all');
    </script>
</body>
</html>
