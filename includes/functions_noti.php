<?php
/**
 * ฟังก์ชันส่งข้อความแจ้งเตือนผ่าน Telegram (Version: cURL)
 */
function sendTelegramWithButtons($chat_id, $message, $leave_id) {
    $token = "7947297640:AAGUTGwIBiEkt0aZs_yWyqXziM3fUC0CNyU";
    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";

    // สร้างปุ่มกดใต้ข้อความ
    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '✅ อนุมัติ', 'callback_data' => "approve_{$leave_id}"],
            ['text' => '❌ ไม่อนุมัติ', 'callback_data' => "reject_{$leave_id}"]
        ]]
    ];

    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard) // ส่งโครงสร้างปุ่มไปพร้อมกัน
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
?>