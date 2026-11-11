<?php
/**
 * API: Log Interaction on Kiosk Content
 * Increments view_count when a user taps on a kiosk item
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../../config/db.php';

try {
    $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
    if ($contentId > 0) {
        $stmt = $pdo->prepare("UPDATE pr_kiosk_contents SET view_count = view_count + 1 WHERE content_id = :id");
        $stmt->execute([':id' => $contentId]);
        echo json_encode(['success' => true]);
        exit();
    }
    echo json_encode(['success' => false, 'message' => 'Invalid content ID']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
