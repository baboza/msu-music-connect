<?php
require_once __DIR__ . '/../../config/db.php';

// First, alter the table
$pdo->exec("ALTER TABLE research_grants MODIFY disbursement_result VARCHAR(255) NULL");

$data = <<<EOT
eae08819	001-1/2568	การศึกษาเปรียบเทียบงิ้วจ้วงของภาคเหนือและภาคใต้ในเขตปกครองตนเองกวางสีจ้วงในประเทศจีน (A comparative study of the Northern and Southern Styles of Zhuang Opera in the Guangxi Zhuang Autonomous Region, China)	อ.สุธาสินี ถีระพันธ์/Ms.Wenying Chen	รายได้				80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/eae08819.รายงาน fulltext.090156.pdf	http://doi.prg/10.31893/multiscience.2025573	ปิดเล่มสมบูรณ์
8b6ccdd7	001-2/2568	การผสมผสานดนตรีพื้นบ้านของจีนฮ่วยยินเหล่าเฉียงในดนตรีร่วมสมัย (The Fusion of Huayin Laoqiang Chinese Folk Music in Contemporary Music)	ผศ.สยาม จวงประโคน/Ms.Yanya Zhu	รายได้	รอบที่ 1	1 ต.ค. 68 - 31 มี.ค. 69		80000	งวดที่ 1	40000			Scopus	Q4			2568			กำลังดำเนินการ
58558d6e	001-3/2568	การอนุรักษ์กู่ฉินร่วมสมัยในมณฑลอันฮุยประชาชนจีน (The Contenporary Preservation of Chinese Guqin in Anhui Province, China)	ผศ.สยาม จวงประโคน/Ms.Na An	รายได้				80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/58558d6e.รายงาน fulltext.094011.pdf	https://doi.org/10.63332/joph.v5i2.453	ปิดเล่มสมบูรณ์
036438be	001-4/2568	การศึกษาเพลงพื้นบ้านชนเผ่าเอ้อลันชุนในประเทศจีน (Chinese Folk Songs of the Elunchun Ethnic Group in Northeast China)	ผศ.สยาม จวงประโคน/Ms.Lili Yan	รายได้				80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/036438be.รายงาน fulltext.094124.pdf	https://doi.org/10.6332/joph.v5i3.859	ปิดเล่มสมบูรณ์
1ace6cf6	001-5/2568	การพัฒนาและลักษณะทางดนตรีของ จื่อยางเหอ โอเปร่า ในเมืองจือกง มณฆลเสฉวน ประเทศจีน (The Development and Musical Characteristics of Ziyanghe Opera in Zigong Ciyt, Sichuan Province, China)	ผศ.วีรยุทธ สีคุณหลิ่ว/Ms.Shan Fan	รายได้	รอบที่ 1	1 ต.ค. 68 - 31 มี.ค. 69		80000	งวดที่ 1	40000			Scopus	Q4			2568			กำลังดำเนินการ
4e688c61	001-6/2568	ฮั่นโอเปร่า ในเมืองอู่ฮั่น มณฑลหูเป่ย ประเทศจีน (Han Opera in Wuhan City, Hubei Province, China)	ผศ.วีรยุทธ สีคุณหลิ่ว/Mr.Yehao Sang	รายได้	รอบที่ 1 , รอบที่ 2	1 ต.ค. 68 - 31 มี.ค. 69	1 เม.ย 69 - 15 ส.ค. 69	80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/4e688c61.รายงาน fulltext.085334.pdf	https://doi.org/10.63332/joph.v5i4.1121	ปิดเล่มสมบูรณ์
e256011f	001-7/2568	การศึกษาพฤติกรรมการแสดงความคิดเห็นของผู้ชมผ่านสื่อออนไลน์ ในการประกวดวงดนตรีพื้นบ้านอีสาน แห่งประเทศไทย (the study of audience behavior in expressing opinions via online media In the Isan folk music competition of Thailand)	อ.นพพล ไชยสน/ผศ.คมกริช การินทร์	รายได้	รอบที่ 1	1 ต.ค. 68 - 31 มี.ค. 69		80000	งวดที่ 1	40000			Scopus	Q4			2568			กำลังดำเนินการ
318b03c4	001-8/2568	การพัฒนาแนวทางการสอนขับร้องประสานเสียงตามแนวคิดของโคดายในโรงเรียนประถมศึกษาในมณฑลเจียงซู ประเทศจีน (The Development of Guidelines for Choir Teaching based on the concept of Kodaly Method in the Primary Schools in Jiangsu province, China.)	อ.สุธาสินี ถีระพันธ์ /Ms.Shujia Zhu	รายได้				80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/318b03c4.รายงาน fulltext.093856.pdf	https://doi.org/10.31893/multirev.2026015	ปิดเล่มสมบูรณ์
6d8fb19e	001-9/2568	การอนุรักษ์และการถ่ายทอดเพลงพื้นบ้านหลัวเอ๋อ : สถานการณ์ปัจจุบันและเทคนิคการ้องเพลงในฉงชิ่ง ประเทศจีน (Preservation and Transmission of Chinese Luo Er Folk Song : Current Status and Singing Techniques in Chongqing,China.)	ผศ.วีรยุทธ สีคุณหลิ่ว/Mr.Liming Xie	รายได้	รอบที่ 1 , รอบที่ 2	1 ต.ค. 68 - 31 มี.ค. 69	1 เม.ย. 69-15 ส.ค. 69	80000	งวดที่ 1 , งวดที่ 2 , งวดที่ 3	40000	24000	16000	Scopus	Q4			2568	Music Research_Files_/6d8fb19e.รายงาน fulltext.032257.pdf	10.31893/multiscience.2026312	ปิดเล่มสมบูรณ์
17d9ba30	001-10/2568	“การพัฒนาหลักสูตรดนตรีศึกษาตามเกณฑ์การประกันคุณภาพการศึกษาของเครือมหาวิทยาลัยอาเซียน (AUN-QA) เพื่อยกระดับคุณภาพการจัดการเรียนการสอนในระดับอุดมศึกษา (Curriculum Development for Music Education Using the ASEAN University Network Duality Assurance (AUN-QA) Standaras to Enhance Teaching and Learning Management in University”	อ.พิษณุ บุญศรีอนันต์	รายได้	รอบที่ 1	1 ต.ค. 68 - 31 มี.ค. 69		80000	งวดที่ 1	40000			Scopus	Q3			2568			กำลังดำเนินการ
01dd89aa	001-11/2568	“มังกรโอบฟ้า นาคาล่องธาร สำหรับวงออร์เคสตรา (Dragon in the Sky, Naka in the Stream for Isan Orchestras)”	ผศ.ณรงค์รัชช์ วรมิตรไมตรี/ผศ.อรรคพงศ์ ภูลายยาว/อ.อาทิตย์ คำหงษ์ศา	รายได้	รอบที่ 1	1 ต.ค. 68 - 31 มี.ค. 69		100000	งวดที่ 1	50000			Scopus	Q2			2568			กำลังดำเนินการ
e4489ac1	001-1/2569	การสร้างแคนวงสากล: การพัฒนาเครื่องดนตรีแคนสู่ระบบวงดนตรีสากลและการประยุกต์ใช้ในบริบทดนตรีร่วมสมัย	ผศ.ดร.ณรงค์รัชช์ วรมิตรไมตรี/อ.สัญชัย ด้วงบุ้ง	รายได้				240000	งวดที่ 1	120000			Scopus	Q1			2569			กำลังดำเนินการ
EOT;

$lines = explode("\n", trim($data));

foreach ($lines as $line) {
    if (empty(trim($line))) continue;
    $cols = explode("\t", trim($line));
    
    // Mapping:
    // 0: Key
    // 1: contract_no
    // 2: project_name
    // 3: co_researchers (since we don't have mapping for researcher_id)
    // 4: funding_source
    // 5: extension_period
    // 6: extension_1
    // 7: extension_2
    // 8: budget
    // 9: disbursement_result
    // 10: installment_1
    // 11: installment_2
    // 12: installment_3
    // 13: publication
    // 14: scopus
    // 15: tci
    // 16: eric
    // 17: funding_year
    // 18: fulltext_report
    // 19: doi
    // 20: status

    $contract_no = $cols[1] ?? '';
    $project_name = $cols[2] ?? '';
    $co_researchers = $cols[3] ?? '';
    $funding_source = $cols[4] ?? '';
    $extension_period = $cols[5] ?? '';
    $extension_1 = $cols[6] ?? '';
    $extension_2 = $cols[7] ?? '';
    $budget = floatval($cols[8] ?? 0);
    $disbursement_result = $cols[9] ?? '';
    $installment_1 = floatval($cols[10] ?? 0);
    $installment_2 = floatval($cols[11] ?? 0);
    $installment_3 = floatval($cols[12] ?? 0);
    $publication = $cols[13] ?? '';
    $scopus = $cols[14] ?? '';
    $tci = $cols[15] ?? '';
    $eric = $cols[16] ?? '';
    $funding_year = $cols[17] ?? '';
    $fulltext_report = $cols[18] ?? '';
    $doi = $cols[19] ?? '';
    $status = $cols[20] ?? '';
    
    if ($status === 'ปิดเล่มสมบูรณ์') {
        $status = 'เสร็จสิ้น';
    }

    $stmt = $pdo->prepare("INSERT INTO research_grants (
        contract_no, project_name, co_researchers, funding_source, 
        extension_period, extension_1, extension_2, budget, disbursement_result,
        installment_1, installment_2, installment_3, publication, scopus, tci, eric,
        funding_year, fulltext_report, doi, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )");
    
    $stmt->execute([
        $contract_no, $project_name, $co_researchers, $funding_source,
        $extension_period, $extension_1, $extension_2, $budget, $disbursement_result,
        $installment_1, $installment_2, $installment_3, $publication, $scopus, $tci, $eric,
        $funding_year, $fulltext_report, $doi, $status
    ]);
}

echo "Import success!";
