<?php
/**
 * push-send.php — Gửi Web Push notification tới tất cả hoặc từng user
 * POST {"secret":"RNI_PUSH_2026","title":"...","body":"...","url":"...","email":"(optional)"}
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/push-encrypt.php';

// ── Xác thực secret ───────────────────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = $input['secret'] ?? '';
if ($secret !== 'RNI_PUSH_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── DB ────────────────────────────────────────────────────────────────────────
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'rni_courses_quiz_RNI_DTVNBT';
$user = getenv('DB_USER') ?: 'rni_courses_quiz';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// ── Lấy subscriptions ─────────────────────────────────────────────────────────
$email = trim($input['email'] ?? '');
if ($email) {
    $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_email=?");
    $stmt->execute([$email]);
} else {
    $stmt = $pdo->query("SELECT * FROM push_subscriptions");
}
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Payload ───────────────────────────────────────────────────────────────────
$payload = json_encode([
    'title' => $input['title'] ?? 'Học viện RNI',
    'body'  => $input['body']  ?? 'Hành trình của bạn đang chờ — tiếp tục học nhé!',
    'url'   => $input['url']   ?? 'https://danh-thuc-vien-ngoc-trong-ban.vercel.app',
    'icon'  => 'https://thuvien.rni.vn/icon-192.png',
]);

// ── Gửi notification ──────────────────────────────────────────────────────────
$ok = 0; $fail = 0; $expired = [];

foreach ($subs as $sub) {
    try {
        $encrypted = encrypt_web_push($payload, $sub['p256dh'], $sub['auth_key']);
        $headers   = make_vapid_headers($sub['endpoint'], strlen($encrypted));
        if (!$headers) { $fail++; continue; }

        $hdr_str = '';
        foreach ($headers as $k => $v) $hdr_str .= "$k: $v\r\n";

        $ctx = stream_context_create(['http' => [
            'method'         => 'POST',
            'header'         => $hdr_str,
            'content'        => $encrypted,
            'ignore_errors'  => true,
            'timeout'        => 10,
        ]]);

        @file_get_contents($sub['endpoint'], false, $ctx);
        $code = 0;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $code = (int)($m[1] ?? 0);
        }

        if ($code >= 200 && $code < 300) {
            $ok++;
        } elseif ($code === 410 || $code === 404) {
            $expired[] = $sub['endpoint'];
            $fail++;
        } else {
            $fail++;
            error_log("[RNI Push] HTTP $code endpoint=" . substr($sub['endpoint'], 0, 60));
        }
    } catch (\Exception $e) {
        $fail++;
        error_log('[RNI Push] ' . $e->getMessage());
    }
}

// Xoá subscriptions hết hạn
foreach ($expired as $ep) {
    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")->execute([$ep]);
}

echo json_encode(['sent' => $ok, 'failed' => $fail, 'expired_removed' => count($expired)]);
