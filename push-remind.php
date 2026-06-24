<?php
/**
 * push-remind.php — Gửi nhắc lịch học tự động (gọi bằng cron job)
 *
 * Cron job (cPanel hoặc server) — 3 mốc:
 *   0  5 * * * curl -s "https://thuvien.rni.vn/push-remind.php?secret=RNI_PUSH_2026&session=morning"
 *   0 11 * * * curl -s "https://thuvien.rni.vn/push-remind.php?secret=RNI_PUSH_2026&session=noon"
 *   0 20 * * * curl -s "https://thuvien.rni.vn/push-remind.php?secret=RNI_PUSH_2026&session=evening"
 *
 * ⚠️ Xoá các cron cũ trên server nếu còn: ~09h55, 12h, 13h.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/push-encrypt.php';

$secret  = $_GET['secret'] ?? $_POST['secret'] ?? '';
$session = $_GET['session'] ?? 'noon';

if ($secret !== 'RNI_PUSH_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Tin nhắn nhắc ─────────────────────────────────────────────────────────────
$morning_messages = [
    ['title' => '🌅 Bài học sáng nay',          'body' => 'Mời bạn bắt đầu ngày mới bằng 10 phút tĩnh lặng cùng bài học sáng nay.'],
    ['title' => '🌿 Chào ngày mới',              'body' => 'Chào ngày mới bằng sự hiện diện trọn vẹn trong bài học cùng tôi bạn nhé.'],
    ['title' => '☀️ Trước khi ngày bắt đầu',     'body' => 'Trước khi ngày mới bắt đầu bận rộn, mình dành chút thời gian vào bài học nhé.'],
    ['title' => '💎 Sáng sớm cùng bài học',      'body' => 'Mời bạn thức dậy cùng bài học sáng nay để viên ngọc trong mình thêm sáng rõ.'],
    ['title' => '🍃 Thảnh thơi lúc sớm mai',     'body' => 'Một chút thảnh thơi lúc sớm mai cùng bài học, bạn sẵn sàng chưa?'],
    ['title' => '🌸 Khởi đầu trong tỉnh thức',  'body' => 'Mời bạn vào bài học sớm nay để khởi đầu một ngày trong sự tỉnh thức.'],
    ['title' => '✨ Phút đầu ngày cho mình',      'body' => 'Dành những phút đầu ngày cho bài học để tâm hồn thêm vững chãi bạn nhé.'],
    ['title' => '🌄 Ngày mới bắt đầu',           'body' => 'Ngày mới đã bắt đầu, mình cùng dành 10 phút vào bài học cho chính mình nhé.'],
];
$noon_messages = [
    ['title' => '☀️ Giờ học trưa nay',        'body' => 'Dành 10 phút nghỉ trưa để mình cùng vào bài học mới nhé bạn.'],
    ['title' => '🌸 Nghỉ trưa cùng bài học',   'body' => 'Một chút tĩnh lặng cùng bài học trưa nay cho tâm hồn thêm sáng rõ nhé.'],
    ['title' => '🌿 Tạm gác công việc nhé',    'body' => 'Đã đến lúc tạm gác công việc để vào bài học dành riêng cho bạn rồi.'],
    ['title' => '🍃 Thảnh thơi trưa nay',      'body' => 'Mời bạn dành ít phút thảnh thơi trưa nay để tiếp tục bài học cùng tôi.'],
    ['title' => '✨ Bài học đang chờ bạn',      'body' => 'Bài học hôm nay đã sẵn sàng, mình cùng vào học một chút nhé bạn.'],
];
$evening_messages = [
    ['title' => '🌙 Trước khi khép lại ngày',  'body' => 'Trước khi khép lại một ngày, mình cùng dành chút thời gian cho bài học nhé.'],
    ['title' => '🕯️ Bài học tối nay',          'body' => '10 phút trọn vẹn cho bài học tối nay để tâm hồn được vỗ về bạn nhé.'],
    ['title' => '🌿 Bài học đang chờ bạn',     'body' => 'Bài học hôm nay vẫn đang chờ bạn ghé thăm, mình vào học một chút nhé.'],
    ['title' => '🌙 Bình yên cuối ngày',       'body' => 'Một chút bình yên với bài học cuối ngày trước khi đi ngủ bạn nhé.'],
    ['title' => '💫 10 phút cho chính mình',   'body' => 'Đừng quên dành 10 phút tối nay để hoàn thành bài học cho chính mình.'],
    ['title' => '🌸 Mài sáng viên ngọc',       'body' => 'Mời bạn vào bài học tối nay để cùng tôi mài sáng viên ngọc bên trong nhé.'],
];

$messages = $session === 'evening' ? $evening_messages : ($session === 'morning' ? $morning_messages : $noon_messages);
$msg      = $messages[array_rand($messages)];

$payload = json_encode([
    'title' => $msg['title'],
    'body'  => $msg['body'],
    'url'   => 'https://danh-thuc-vien-ngoc-trong-ban.vercel.app',
    'icon'  => 'https://thuvien.rni.vn/icon-192.png',
]);

// ── DB & gửi ──────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'rni_courses_quiz_RNI_DTVNBT';
$user = 'rni_quiz_user';
$pass = 'RNI-quiz-dtvnbt';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// Bỏ qua học viên đã hoàn thành khoá học (progress_pct = 100)
$subs = $pdo->query("
    SELECT ps.* FROM push_subscriptions ps
    WHERE ps.user_email = ''
       OR ps.user_email COLLATE utf8mb4_unicode_ci NOT IN (
           SELECT DISTINCT email FROM user_progress_v2
           WHERE progress_pct >= 100
       )
")->fetchAll(PDO::FETCH_ASSOC);

$ok = 0; $fail = 0; $expired = [];

foreach ($subs as $sub) {
    try {
        $encrypted = encrypt_web_push($payload, $sub['p256dh'], $sub['auth_key']);
        $headers   = make_vapid_headers($sub['endpoint'], strlen($encrypted));
        if (!$headers) { $fail++; continue; }

        $hdr_str = '';
        foreach ($headers as $k => $v) $hdr_str .= "$k: $v\r\n";

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => $hdr_str,
            'content'       => $encrypted,
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);

        @file_get_contents($sub['endpoint'], false, $ctx);
        $code = 0;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $code = (int)($m[1] ?? 0);
        }

        if ($code >= 200 && $code < 300)    $ok++;
        elseif ($code === 410 || $code === 404) { $expired[] = $sub['endpoint']; $fail++; }
        else {
            $fail++;
            error_log("[RNI Push] HTTP $code endpoint=" . substr($sub['endpoint'], 0, 60));
        }
    } catch (\Exception $e) {
        $fail++;
        error_log('[RNI Push] ' . $e->getMessage());
    }
}

foreach ($expired as $ep) {
    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")->execute([$ep]);
}

echo json_encode([
    'session'         => $session,
    'message_used'    => $msg['title'],
    'sent'            => $ok,
    'failed'          => $fail,
    'expired_removed' => count($expired),
]);
