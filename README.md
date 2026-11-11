# Music MSU Connect ERP 2.0
**ระบบสำนักงานอิเล็กทรอนิกส์และบริการบุคลากร วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม**
*(College of Music, Mahasarakham University)*

---

## 📌 ภาพรวมระบบ (Overview)
**Music MSU Connect** เป็นระบบบริหารจัดการองค์กรแบบบูรณาการ (Enterprise Resource Planning - ERP 2.0) ที่ได้รับการออกแบบให้รองรับทั้งการใช้งานบนเดสก์ท็อป และสมาร์ทโฟน (Mobile-First Web App / PWA) เพื่ออำนวยความสะดวกแก่อาจารย์ บุคลากรสายสนับสนุน และผู้บริหารในการดำเนินงานประจำวันอย่างมีประสิทธิภาพ ไร้รอยต่อ และลดการใช้กระดาษ (Paperless)

---

## ✨ โมดูลและฟังก์ชันหลัก (Core Modules)

1. **ศูนย์บัญชาการและหน้าแรกสำหรับมือถือ (Unified Command Center & Mobile App):**
   - การลงเวลาปฏิบัติงาน (Attendance Pulse Check-in/Out)
   - สรุปงานรออนุมัติแบบรวมศูนย์ (Universal Approvals)
   - สิทธิ์และการลาคงเหลือ (Leave Balance Tracker)
   - ระบบกริดแอปพลิเคชัน 3 คอลัมน์ที่รองรับการใช้งานด้วยมือเดียวอย่างสะดวกสบาย
   - รองรับ Dark Mode และ Light Mode แบบปรับเปลี่ยนตามผู้ใช้งาน

2. **ระบบบันทึกการปฏิบัติงานประจำวัน (Daily Report & Worklog):**
   - บันทึกงานประจำวัน งานตามคำสั่ง และงานมอบหมายพิเศษ
   - ระบุสถานที่ (Onsite / Work from Home) พร้อมแนบไฟล์ภาพหรือเอกสาร
   - ระบบสรุปรายงานประจำเดือนและประวัติย้อนหลัง

3. **ระบบการลาอิเล็กทรอนิกส์ (e-Leave Management):**
   - ยื่นใบลาออนไลน์ (ลาพักผ่อน, ลาป่วย, ลากิจ, ลาคลอด ฯลฯ)
   - คำนวณวันลาคงเหลืออัตโนมัติตามระเบียบมหาวิทยาลัย
   - เส้นทางการอนุมัติตามลำดับบังคับบัญชา

4. **ระบบการประชุมอิเล็กทรอนิกส์ (e-Meeting Paperless):**
   - ตารางการประชุมคณะกรรมการและวาระการประชุม
   - ระบบแนบเอกสารประกอบวาระและรายงานการประชุม
   - ระบบเช็คชื่อเข้าร่วมประชุมออนไลน์/ออนไซต์

5. **ระบบจองห้องและสถานที่ (Room & Practice Room Booking):**
   - จองห้องซ้อมดนตรีเดี่ยวและกลุ่ม
   - จองห้องประชุม ห้องเรียน และสตูดิโอดนตรี พร้อมปฏิทินแสดงความพร้อมใช้งาน

6. **ระบบพัสดุและแจ้งซ่อมบำรุง (Supplies & Repairs):**
   - เบิกพัสดุสำนักงานและตรวจสอบยอดคงเหลือ
   - แจ้งซ่อมบำรุงอาคารและอุปกรณ์ดนตรี พร้อมติดตามสถานะงานซ่อม

7. **ระบบติดตามโครงการยุทธศาสตร์และงบประมาณ (Strategic Project Tracking):**
   - ติดตามความก้าวหน้าโครงการตามแผนปฏิบัติราชการ
   - บันทึกการเบิกจ่ายงบประมาณและผลลัพธ์ตัวชี้วัด (KPIs)

---

## 🛠️ สถาปัตยกรรมเทคโนโลยี (Tech Stack)

- **Backend:** PHP 8.x (Native PDO, RESTful endpoints)
- **Database:** MySQL / MariaDB (InnoDB, UTF8mb4)
- **Frontend:** HTML5, CSS3 (Modern Flexbox & CSS Grid), Bootstrap 5.3, FontAwesome 6
- **Dynamic UX:** HTMX (AJAX transitions without page reload), Vanilla JavaScript
- **Mobile Packaging:** Progressive Web App (PWA) / Trusted Web Activity (TWA for Google Play Store)

---

## 🚀 การติดตั้งและใช้งาน (Installation)

1. **โคลนคลังข้อมูล (Clone Repository):**
   ```bash
   git clone https://github.com/baboza/msu-music-connect.git
   ```

2. **นำเข้าฐานข้อมูล (Database Import):**
   - สร้างฐานข้อมูล MySQL ชื่อ `e-office` (หรือชื่อตามต้องการ)
   - นำเข้าไฟล์ SQL โครงสร้างหลักและตารางจากโฟลเดอร์ `/sql/`

3. **ตั้งค่าการเชื่อมต่อฐานข้อมูล (Database Config):**
   - แก้ไขไฟล์ `config/db.php` กำหนด Host, Username, Password ให้ตรงกับเซิร์ฟเวอร์

4. **กำหนดสิทธิ์โฟลเดอร์ (File Permissions):**
   - โฟลเดอร์ `uploads/` ต้องมีสิทธิ์เขียนไฟล์ (Write Permission / chmod 755 หรือ 777)

---

## 📱 แนวทางการนำขึ้น Google Play Store (Play Store Deployment Guide)

เนื่องจากระบบนี้เป็น Web Application ฐานข้อมูล PHP/MySQL บนเซิร์ฟเวอร์ การนำขึ้น **Google Play Store** จะใช้สถาปัตยกรรม **Trusted Web Activity (TWA)** หรือ **PWABuilder / Bubblewrap** ตามมาตรฐานอย่างเป็นทางการของ Google:

1. **Deploy Production Server:** นำโค้ดขึ้นเซิร์ฟเวอร์จริงที่มี Domain Name และรองรับ HTTPS/SSL 100% (เช่น `https://music.msu.ac.th/connect`)
2. **Web App Manifest & Asset Links:** ติดตั้ง `manifest.json` และ `.well-known/assetlinks.json` เพื่อยืนยันความเป็นเจ้าของโดเมนกับ Google
3. **Build Android Package (.aab):** ใช้ Google Bubblewrap CLI หรือ PWABuilder เพื่อ Generate ไฟล์ Android App Bundle (`.aab`) พร้อม Keystore Signing Key
4. **Publish on Play Console:** อัปโหลด `.aab` ขึ้น Google Play Console เพื่อเปิดให้ดาวน์โหลดบน Google Play Store

---

## 📄 ลิขสิทธิ์และการพัฒนา (License & Credits)
- พัฒนาสำหรับ: **วิทยาลัยดุริยางคศิลป์ มหาวิทยาลัยมหาสารคาม**
- ผู้ดูแลระบบ: ทีมงานเทคโนโลยีสารสนเทศ วิทยาลัยดุริยางคศิลป์
