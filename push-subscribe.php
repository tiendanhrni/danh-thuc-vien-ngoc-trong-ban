<?php
/**
 * push-subscribe.php — Lưu / xoá Web Push subscription
 * POST {"action":"subscribe","endpoint":...,"keys":{"p256dh":...,"auth":...},"user_email":...}
 * POST {"action":"unsubscribe","endpoint":...}
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// ── DB ────────────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'rni_courses_quiz_RNI_DTVNBT';
$user = 'rni_quiz_user';
$pass = 'RNI-quiz-dtvnbt';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Lỗi kết nối DB']);
    exit;
}

// Tạo bảng nếu chưa có
$pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    endpoint    TEXT NOT NULL,
    p256dh      TEXT NOT NULL,
    auth_key    VARCHAR(255) NOT NULL,
    user_email  VARCHAR(255) DEFAULT '',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_endpoint (endpoint(500))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── action: subscribe ─────────────────────────────────────────────────────────
if ($action === 'subscribe') {
    $endpoint   = trim($input['endpoint'] ?? '');
    $p256dh     = trim($input['keys']['p256dh'] ?? '');
    $auth       = trim($input['keys']['auth'] ?? '');
    $user_email = mb_substr(trim($input['user_email'] ?? ''), 0, 255);

    if (!$endpoint || !$p256dh || !$auth) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu thông tin subscription']);
        exit;
    }

    $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth_key, user_email)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth_key=VALUES(auth_key), user_email=VALUES(user_email)")
        ->execute([$endpoint, $p256dh, $auth, $user_email]);

    echo json_encode(['success' => true]);
    exit;
}

// ── action: unsubscribe ───────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $endpoint = trim($input['endpoint'] ?? '');
    if ($endpoint) {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")->execute([$endpoint]);
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action không hợp lệ']);
