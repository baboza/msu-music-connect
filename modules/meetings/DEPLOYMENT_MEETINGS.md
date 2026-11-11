# คู่มือการนำระบบการประชุม (E-Meeting) ขึ้นโฮสต์จริง
> ไฟล์คู่มือหลักฉบับสมบูรณ์อยู่ที่: [DEPLOYMENT_GUIDE.md](../../DEPLOYMENT_GUIDE.md)

### ไฟล์ที่ต้องอัปโหลดในรอบนี้:
1. `modules/meetings/manage_agendas.php`
2. `modules/meetings/index.php`
3. `modules/meetings/dashboard_admin.php`
4. `modules/meetings/meeting_room.php`
5. `modules/meetings/manage_meeting.php`
6. `modules/meetings/document_center.php`
7. `modules/meetings/draft_minutes.php`
8. `modules/meetings/view.php`
9. `modules/meetings/ajax_heartbeat.php` (ระบบรักษาเซสชันอัตโนมัติ)
10. `uploads/meetings/.htaccess`

### สคริปต์ SQL ที่ต้องรัน:
- รันไฟล์ `deploy_meetings.sql` ใน phpMyAdmin (หรือดูคำสั่ง SQL เต็มใน `DEPLOYMENT_GUIDE.md`)
- มีตารางสำคัญคือ `meeting_submissions` (กล่องรับเอกสารวาระ) และตารางการประชุมอื่นๆ โดยใช้ `CREATE TABLE IF NOT EXISTS` ทั้งหมด ไม่กระทบข้อมูลเดิม

### สิทธิ์โฟลเดอร์:
- `uploads/meetings` -> `chmod 775` หรือ `chmod 777`
