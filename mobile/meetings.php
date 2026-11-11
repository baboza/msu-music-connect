<?php
require_once 'auth_check.php';
require_once 'includes/header.php';

$filter_status = $_GET['tab'] ?? 'upcoming';
if (!in_array($filter_status, ['upcoming', 'past'], true)) {
    $filter_status = 'upcoming';
}

// ฟังก์ชันแปลงวันที่ภาษาไทย
if (!function_exists('formatThaiDateShort')) {
    function formatThaiDateShort($date_str) {
        if (!$date_str) return ['day' => '-', 'month' => '-', 'year' => '-', 'full' => '-'];
        $ts = strtotime($date_str);
        $months = [1=>'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        $m = (int)date('n', $ts);
        $d = date('j', $ts);
        $y = (int)date('Y', $ts) + 543;
        $y_short = substr((string)$y, -2);
        return [
            'day' => $d,
            'month' => $months[$m] ?? '',
            'year' => $y,
            'year_short' => "'" . $y_short,
            'full' => "{$d} " . ($months[$m] ?? '') . " {$y}"
        ];
    }
}

// ---------------------------------------------------------
// ดึงข้อมูลการประชุม
// ---------------------------------------------------------
try {
    if ($filter_status === 'past') {
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   (SELECT COUNT(*) FROM meeting_agendas WHERE meeting_id = m.meeting_id) as agenda_count,
                   (SELECT COUNT(*) FROM meeting_attachments a JOIN meeting_agendas ag ON a.agenda_id = ag.agenda_id WHERE ag.meeting_id = m.meeting_id) as attachment_count,
                   att.attendance_status as my_attendance,
                   att.attend_mode as my_attend_mode
            FROM meetings m 
            LEFT JOIN meeting_attendees att ON m.meeting_id = att.meeting_id AND att.user_id = ?
            WHERE (m.meeting_date < CURDATE() AND m.status != 'in_progress') OR m.status IN ('completed', 'cancelled')
            ORDER BY m.meeting_date DESC, m.start_time DESC
        ");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   (SELECT COUNT(*) FROM meeting_agendas WHERE meeting_id = m.meeting_id) as agenda_count,
                   (SELECT COUNT(*) FROM meeting_attachments a JOIN meeting_agendas ag ON a.agenda_id = ag.agenda_id WHERE ag.meeting_id = m.meeting_id) as attachment_count,
                   att.attendance_status as my_attendance,
                   att.attend_mode as my_attend_mode
            FROM meetings m 
            LEFT JOIN meeting_attendees att ON m.meeting_id = att.meeting_id AND att.user_id = ?
            WHERE (m.meeting_date >= CURDATE() AND m.status NOT IN ('completed', 'cancelled')) OR m.status = 'in_progress'
            ORDER BY m.meeting_date ASC, m.start_time ASC
        ");
        $stmt->execute([$user_id]);
    }
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $meetings = [];
}

// นับจำนวนแยกตามแท็บ
try {
    $upcoming_count = (int)$pdo->query("
        SELECT COUNT(*) FROM meetings 
        WHERE (meeting_date >= CURDATE() AND status NOT IN ('completed', 'cancelled')) OR status = 'in_progress'
    ")->fetchColumn();

    $past_count = (int)$pdo->query("
        SELECT COUNT(*) FROM meetings 
        WHERE (meeting_date < CURDATE() AND status != 'in_progress') OR status IN ('completed', 'cancelled')
    ")->fetchColumn();
} catch (PDOException $e) {
    $upcoming_count = 0;
    $past_count = 0;
}
?>

<style>
/* Modern Meeting Redesign Styles */
.meeting-tab-segment {
    display: flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 14px;
    position: relative;
    border: 1px solid rgba(0, 0, 0, 0.05);
    width: 100%;
    box-sizing: border-box;
}
[data-bs-theme="dark"] .meeting-tab-segment {
    background: #1e293b;
    border-color: rgba(255, 255, 255, 0.08);
}
.meeting-tab-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
[data-bs-theme="dark"] .meeting-tab-btn {
    color: #94a3b8;
}
.meeting-tab-btn.active {
    background: #ffffff;
    color: var(--msu-primary, #39048f);
    box-shadow: 0 4px 12px rgba(57, 4, 143, 0.12);
}
[data-bs-theme="dark"] .meeting-tab-btn.active {
    background: #334155;
    color: #f8fafc;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
}
.meeting-tab-btn .badge-counter {
    font-size: 0.72rem;
    padding: 2px 7px;
    border-radius: 20px;
    background: rgba(100, 116, 139, 0.15);
    color: inherit;
    font-weight: 700;
}
.meeting-tab-btn.active .badge-counter {
    background: rgba(57, 4, 143, 0.12);
    color: var(--msu-primary, #39048f);
}
[data-bs-theme="dark"] .meeting-tab-btn.active .badge-counter {
    background: rgba(255, 255, 255, 0.15);
    color: #f8fafc;
}

/* Meeting Bento Card */
.meeting-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(0, 0, 0, 0.06);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    padding: 14px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    text-decoration: none;
    display: block;
    color: inherit;
    position: relative;
    overflow: hidden;
    width: 100%;
    box-sizing: border-box;
}
[data-bs-theme="dark"] .meeting-card {
    background: #1e293b;
    border-color: rgba(255, 255, 255, 0.07);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}
.meeting-card:active {
    transform: scale(0.985);
}
.meeting-card:hover {
    border-color: rgba(57, 4, 143, 0.25);
    box-shadow: 0 8px 24px rgba(57, 4, 143, 0.08);
}
[data-bs-theme="dark"] .meeting-card:hover {
    border-color: rgba(168, 85, 247, 0.35);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

/* Date Box */
.meeting-date-box {
    width: 52px;
    min-width: 52px;
    border-radius: 12px;
    overflow: hidden;
    text-align: center;
    border: 1px solid rgba(57, 4, 143, 0.15);
    background: #ffffff;
}
[data-bs-theme="dark"] .meeting-date-box {
    background: #0f172a;
    border-color: rgba(255, 255, 255, 0.1);
}
.meeting-date-box .month-header {
    background: linear-gradient(135deg, #39048f, #5b13cf);
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.meeting-date-box.past .month-header {
    background: #64748b;
}
.meeting-date-box .day-number {
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.2;
    padding: 4px 0 2px 0;
    color: #1e293b;
}
[data-bs-theme="dark"] .meeting-date-box .day-number {
    color: #f1f5f9;
}
.meeting-date-box.past .day-number {
    color: #64748b;
}

/* Pulsing Badge */
.pulse-indicator {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    margin-right: 5px;
    animation: livePulse 1.6s infinite;
}
@keyframes livePulse {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Card Strip Pill */
.card-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 8px;
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
}
[data-bs-theme="dark"] .card-meta-pill {
    background: #0f172a;
    color: #94a3b8;
    border-color: #334155;
}

.meeting-search-box {
    position: relative;
}
.meeting-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.88rem;
}
.meeting-search-box input {
    padding-left: 38px !important;
    border-radius: 12px;
    font-size: 0.85rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}
[data-bs-theme="dark"] .meeting-search-box input {
    background: #1e293b;
    border-color: #334155;
    color: #f1f5f9;
}
</style>

<div class="mt-2 pb-5">
    <!-- Top Action Bar -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-light rounded-circle shadow-sm border p-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" hx-boost="true">
                <i class="fa-solid fa-arrow-left text-dark"></i>
            </a>
            <div>
                <h5 class="fw-bold mb-0 text-dark">การประชุม e-Meeting</h5>
                <small class="text-muted">วาระและเอกสารการประชุมคณะ</small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-1">
            <span class="badge rounded-pill px-2.5 py-1.5" style="background: rgba(57, 4, 143, 0.08); color: var(--msu-primary, #39048f); font-weight: 700; font-size: 0.75rem;">
                <i class="fa-solid <?= $filter_status === 'upcoming' ? 'fa-calendar-check' : 'fa-box-archive' ?> me-1"></i>
                <?= count($meetings) ?> รายการ
            </span>
        </div>
    </div>

    <!-- Segmented Tabs: เร็วๆ นี้ vs ที่ผ่านมา -->
    <div class="meeting-tab-segment mb-3">
        <a href="meetings.php?tab=upcoming" class="meeting-tab-btn <?= $filter_status === 'upcoming' ? 'active' : '' ?>" hx-boost="true">
            <i class="fa-solid fa-calendar-day"></i>
            <span>เร็วๆ นี้</span>
            <span class="badge-counter"><?= $upcoming_count ?></span>
        </a>
        <a href="meetings.php?tab=past" class="meeting-tab-btn <?= $filter_status === 'past' ? 'active' : '' ?>" hx-boost="true">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>ที่ผ่านมา</span>
            <span class="badge-counter"><?= $past_count ?></span>
        </a>
    </div>

    <!-- Search Filter Bar -->
    <div class="meeting-search-box mb-3">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="meetingSearchInput" class="form-control form-control-sm shadow-none" placeholder="ค้นหาชื่อการประชุม, ครั้งที่, ห้อง..." onkeyup="filterMeetingCards()">
    </div>

    <!-- Meeting Cards Container -->
    <div id="meetingListContainer" class="d-flex flex-column gap-3">
        <?php if (empty($meetings)): ?>
            <!-- Empty State -->
            <div class="meeting-card text-center py-5">
                <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: rgba(57, 4, 143, 0.08); color: var(--msu-primary, #39048f);">
                    <?php if ($filter_status === 'upcoming'): ?>
                        <i class="fa-regular fa-calendar-check fa-2x"></i>
                    <?php else: ?>
                        <i class="fa-solid fa-folder-open fa-2x"></i>
                    <?php endif; ?>
                </div>
                <h6 class="fw-bold text-dark mb-1">
                    <?= $filter_status === 'upcoming' ? 'ไม่มีการประชุมเร็วๆ นี้' : 'ไม่มีประวัติการประชุมที่ผ่านมา' ?>
                </h6>
                <p class="text-muted small mb-0 px-4">
                    <?= $filter_status === 'upcoming' ? 'เมื่อมีกำหนดการประชุมใหม่ รายการจะแสดงขึ้นที่นี่อัตโนมัติ' : 'การประชุมที่เสร็จสิ้นแล้วทั้งหมดจะถูกจัดเก็บไว้ในแท็บนี้' ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($meetings as $m): 
                $m_date_raw = $m['meeting_date'];
                $m_date = strtotime($m_date_raw);
                $today = strtotime(date('Y-m-d'));
                $diff_days = floor(($m_date - $today) / 86400);
                $thai_date = formatThaiDateShort($m_date_raw);

                // Date Badge Status
                $date_tag_class = 'bg-secondary-subtle text-secondary';
                $date_tag_label = $thai_date['full'];
                $is_live = ($m['status'] === 'in_progress');

                if ($is_live) {
                    $date_tag_class = 'bg-success text-white';
                    $date_tag_label = 'กำลังประชุม';
                } elseif ($diff_days === 0) {
                    $date_tag_class = 'bg-danger text-white';
                    $date_tag_label = 'วันนี้';
                } elseif ($diff_days === 1) {
                    $date_tag_class = 'bg-warning text-dark';
                    $date_tag_label = 'พรุ่งนี้';
                } elseif ($diff_days > 1 && $diff_days <= 7) {
                    $date_tag_class = 'bg-purple-subtle text-purple';
                    $date_tag_label = 'อีก ' . $diff_days . ' วัน';
                } elseif ($diff_days < 0) {
                    $date_tag_class = 'bg-light text-muted border';
                    $date_tag_label = 'เสร็จสิ้น';
                }

                // Meeting Type / Status Pill
                $status_pill = '';
                if ($is_live) {
                    $status_pill = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><span class="pulse-indicator"></span>LIVE</span>';
                } elseif ($m['status'] === 'completed') {
                    $status_pill = '<span class="badge bg-secondary-subtle text-secondary border px-2 py-1">เสร็จสิ้น</span>';
                } elseif ($m['status'] === 'cancelled') {
                    $status_pill = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">ยกเลิก</span>';
                }

                // RSVP Badge
                $my_rsvp = $m['my_attendance'] ?? null;
                $rsvp_pill = '';
                if ($my_rsvp === 'attending') {
                    $mode_text = ($m['my_attend_mode'] === 'online') ? 'Online' : 'Onsite';
                    $rsvp_pill = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-check me-1"></i>เข้าร่วม (' . $mode_text . ')</span>';
                } elseif ($my_rsvp === 'absent') {
                    $rsvp_pill = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-xmark me-1"></i>ลาประชุม</span>';
                } elseif ($my_rsvp === 'pending') {
                    $rsvp_pill = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="fa-regular fa-clock me-1"></i>รอตอบรับ</span>';
                }

                $time_str = '';
                if (!empty($m['start_time'])) {
                    $time_str = date('H:i', strtotime($m['start_time']));
                    if (!empty($m['end_time'])) {
                        $time_str .= ' - ' . date('H:i', strtotime($m['end_time'])) . ' น.';
                    } else {
                        $time_str .= ' น. เป็นต้นไป';
                    }
                }
            ?>
                <a href="meetings_view.php?id=<?= $m['meeting_id'] ?>" 
                   class="meeting-card meeting-item" 
                   data-search="<?= htmlspecialchars(strtolower($m['title'] . ' ' . $m['meeting_no'] . ' ' . $m['location'])) ?>"
                   hx-boost="true">
                    
                    <!-- Top Bar: Meeting No, Status Pills & Date Status Tag -->
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2 pb-1">
                        <div class="d-flex align-items-center gap-1.5 flex-wrap">
                            <span class="badge px-2 py-1" style="background: rgba(57, 4, 143, 0.08); color: var(--msu-primary, #39048f); font-weight: 700; font-size: 0.72rem;">
                                ครั้งที่ <?= htmlspecialchars($m['meeting_no'] ?: '-') ?>
                            </span>
                            <?= $status_pill ?>
                            <?= $rsvp_pill ?>
                        </div>
                        <span class="badge <?= $date_tag_class ?> px-2 py-1 fw-bold extra-small flex-shrink-0">
                            <?= $date_tag_label ?>
                        </span>
                    </div>

                    <!-- Main: Date Box + Title -->
                    <div class="d-flex align-items-start gap-3 mb-2.5">
                        <!-- Left Calendar Block -->
                        <div class="meeting-date-box <?= ($filter_status === 'past' && !$is_live) ? 'past' : '' ?>">
                            <div class="month-header"><?= $thai_date['month'] ?></div>
                            <div class="day-number"><?= $thai_date['day'] ?></div>
                        </div>

                        <!-- Right Title -->
                        <div class="flex-grow-1" style="min-width: 0;">
                            <h6 class="fw-bold text-dark mb-0 line-clamp-2" style="line-height: 1.4; font-size: 0.94rem;">
                                <?= htmlspecialchars($m['title']) ?>
                            </h6>
                        </div>
                    </div>

                    <!-- Location & Time Info -->
                    <div class="d-flex flex-column gap-1.5 pt-2 pb-2.5 text-muted small" style="font-size: 0.8rem;">
                        <?php if ($time_str): ?>
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-regular fa-clock text-purple" style="width: 15px;"></i>
                                <span><?= $thai_date['full'] ?> (<?= $time_str ?>)</span>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-location-dot text-purple" style="width: 15px;"></i>
                            <span class="text-truncate"><?= htmlspecialchars($m['location'] ?: 'ห้องประชุมคณะดุริยางคศิลป์ / ไม่ระบุ') ?></span>
                        </div>
                    </div>

                    <!-- Footer: Agenda / Attachments / CTA -->
                    <div class="d-flex justify-content-between align-items-center pt-2.5 border-top border-light-subtle flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="card-meta-pill">
                                <i class="fa-solid fa-list-ol text-purple"></i>
                                <?= (int)$m['agenda_count'] ?> วาระ
                            </span>
                            <span class="card-meta-pill">
                                <i class="fa-solid fa-paperclip text-purple"></i>
                                <?= (int)$m['attachment_count'] ?> เอกสาร
                            </span>
                        </div>
                        <span class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold extra-small d-flex align-items-center gap-1 ms-auto" style="font-size: 0.75rem;">
                            ดูรายละเอียด
                            <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem;"></i>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Empty Search Results Message (Hidden by default) -->
    <div id="noSearchResults" class="meeting-card text-center py-5 d-none" style="display: none;">
        <i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2 opacity-50"></i>
        <h6 class="fw-bold text-dark mb-1">ไม่พบการประชุมที่ค้นหา</h6>
        <p class="text-muted small mb-0">ลองค้นหาด้วยคำอื่น เช่น ชื่องาน, ครั้งที่ หรือห้องประชุม</p>
    </div>

    <div style="height: 60px;"></div>
</div>

<script>
function filterMeetingCards() {
    const query = document.getElementById('meetingSearchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.meeting-item');
    let visibleCount = 0;

    items.forEach(item => {
        const searchText = item.getAttribute('data-search') || '';
        if (query === '' || searchText.includes(query)) {
            item.style.display = 'block';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });

    const noResult = document.getElementById('noSearchResults');
    if (noResult) {
        if (visibleCount === 0 && items.length > 0) {
            noResult.style.display = 'block';
            noResult.classList.remove('d-none');
        } else {
            noResult.style.display = 'none';
            noResult.classList.add('d-none');
        }
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
