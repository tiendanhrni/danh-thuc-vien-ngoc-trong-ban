<?php
/**
 * kajabi-sync.php — Đồng bộ tiến độ học viên từ DB → Kajabi tags
 *
 * Cron job (chạy mỗi ngày lúc 8h sáng):
 *   0 8 * * * curl -s "https://thuvien.rni.vn/kajabi-sync.php?secret=RNI_KAJABI_2026"
 *
 * Tags sẽ được gán trên Kajabi:
 *   rni-xong-quiz-chua-hoc   → Xong quiz, chưa bắt đầu học
 *   rni-dang-hoc             → Đang học (1–99%)
 *   rni-hoan-thanh           → Hoàn thành 100%
 *   rni-inactive-1d          → Không vào > 1 ngày
 *   rni-inactive-2d          → Không vào > 2 ngày
 *   rni-inactive-4d          → Không vào > 4 ngày
 *   rni-inactive-7d          → Không vào > 7 ngày
 *
 * Kajabi Automation ví dụ:
 *   Tag "rni-dang-hoc" + Tag "rni-inactive-4d" → gửi email động viên
 *   Tag "rni-xong-quiz-chua-hoc" + Tag "rni-inactive-2d" → gửi email mời bắt đầu
 */

header('Content-Type: application/json; charset=utf-8');

// ── Bảo mật ──────────────────────────────────────────────────────────────────
$secret = $_GET['secret'] ?? '';
if ($secret !== 'RNI_KAJABI_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Cấu hình Kajabi ──────────────────────────────────────────────────────────
// Lấy tại: Kajabi → Settings → Integrations → API Credentials
define('KAJABI_CLIENT_ID',     '2pNY4LLuQeDZW6bP4SwoGQPS');
define('KAJABI_CLIENT_SECRET', 'YOUR_API_SECRET_HERE');      // dán API Secret vào đây
define('KAJABI_BASE_URL',      'https://kajabi.com/api/v1');

// ── Kết nối DB ───────────────────────────────────────────────────────────────
$db_host = 'localhost';
$db_name = 'rni_courses_quiz_RNI_DTVNBT';
$db_user = 'rni_quiz_user';
$db_pass = 'RNI-quiz-dtvnbt';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

// ── Lấy tất cả học viên + tính nhóm ─────────────────────────────────────────
$users = $pdo->query("
    SELECT
        email,
        MAX(name)                                           AS name,
        MAX(progress_pct)                                   AS max_pct,
        MAX(created_at)                                     AS last_activity,
        SUM(event = 'quiz_complete')                        AS has_quiz,
        SUM(event IN ('lesson_open','lesson_complete'))      AS has_lesson
    FROM user_progress_v2
    WHERE email != ''
    GROUP BY email
")->fetchAll();

// ── Hàm xác định nhóm ────────────────────────────────────────────────────────
function get_group($u) {
    $pct = (int)$u['max_pct'];
    if ($pct >= 100)                              return 'hoan_thanh';
    if ((int)$u['has_lesson'] > 0 && $pct > 0)   return 'dang_hoc';
    return 'xong_quiz_chua_hoc';
}

function get_inactive_tags($last_activity) {
    $days = (time() - strtotime($last_activity)) / 86400;
    $tags = [];
    if ($days > 1) $tags[] = 'rni-inactive-1d';
    if ($days > 2) $tags[] = 'rni-inactive-2d';
    if ($days > 4) $tags[] = 'rni-inactive-4d';
    if ($days > 7) $tags[] = 'rni-inactive-7d';
    return $tags;
}

// ── Lấy OAuth2 access token từ Kajabi ────────────────────────────────────────
function kajabi_get_token() {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
        'content'       => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => KAJABI_CLIENT_ID,
            'client_secret' => KAJABI_CLIENT_SECRET,
        ]),
        'ignore_errors' => true,
        'timeout'       => 15,
    ]]);
    $res  = @file_get_contents('https://kajabi.com/oauth/token', false, $ctx);
    $data = json_decode($res ?: '{}', true);
    if (empty($data['access_token'])) {
        error_log('[RNI Kajabi] Không lấy được token: ' . $res);
        return null;
    }
    return $data['access_token'];
}

// ── Hàm gọi Kajabi API ───────────────────────────────────────────────────────
function kajabi_request($method, $path, $body = null, $token = '') {
    $url  = KAJABI_BASE_URL . $path;
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ]),
            'ignore_errors' => true,
            'timeout'       => 15,
        ]
    ];
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }
    $ctx      = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);
    $code     = 0;
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $code = (int)($m[1] ?? 0);
    }
    return ['code' => $code, 'body' => json_decode($response ?: '{}', true)];
}

// Tìm person trên Kajabi theo email
function kajabi_find_person($email, $token) {
    $res = kajabi_request('GET', '/people?email=' . urlencode($email), null, $token);
    if ($res['code'] === 200 && !empty($res['body']['people'][0])) {
        return $res['body']['people'][0];
    }
    return null;
}

// Gán tags cho person
function kajabi_add_tags($person_id, array $tags, $token) {
    return kajabi_request('POST', "/people/{$person_id}/tags", ['tags' => $tags], $token);
}

// Xoá các tag RNI cũ trước khi gán lại
function kajabi_remove_rni_tags($person_id, $token) {
    $all_rni_tags = [
        'rni-xong-quiz-chua-hoc', 'rni-dang-hoc', 'rni-hoan-thanh',
        'rni-inactive-1d', 'rni-inactive-2d', 'rni-inactive-4d', 'rni-inactive-7d',
    ];
    return kajabi_request('DELETE', "/people/{$person_id}/tags", ['tags' => $all_rni_tags], $token);
}

// ── Lấy token trước khi đồng bộ ─────────────────────────────────────────────
$token = kajabi_get_token();
if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'Không lấy được Kajabi access token. Kiểm tra lại API Key và Secret.']);
    exit;
}

// ── Đồng bộ từng học viên ────────────────────────────────────────────────────
$results = ['synced' => 0, 'not_found' => 0, 'failed' => 0, 'skipped' => 0];
$not_found_emails = [];

foreach ($users as $u) {
    $email = $u['email'];

    // Bỏ qua email rỗng hoặc không hợp lệ
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $results['skipped']++;
        continue;
    }

    // Tìm người dùng trên Kajabi
    $person = kajabi_find_person($email, $token);
    if (!$person) {
        $results['not_found']++;
        $not_found_emails[] = $email;
        continue;
    }

    $person_id = $person['id'];

    // Xoá tags RNI cũ
    kajabi_remove_rni_tags($person_id, $token);

    // Tính tags mới
    $group = get_group($u);
    $new_tags = ['rni-' . str_replace('_', '-', $group)];
    $inactive_tags = get_inactive_tags($u['last_activity']);
    $new_tags = array_merge($new_tags, $inactive_tags);

    // Gán tags mới
    $res = kajabi_add_tags($person_id, $new_tags, $token);
    if ($res['code'] >= 200 && $res['code'] < 300) {
        $results['synced']++;
    } else {
        $results['failed']++;
        error_log("[RNI Kajabi] Failed {$email} HTTP {$res['code']}");
    }

    // Nghỉ nhẹ để tránh rate limit Kajabi
    usleep(300000); // 0.3 giây
}

echo json_encode([
    'synced'           => $results['synced'],
    'not_found_kajabi' => $results['not_found'],
    'failed'           => $results['failed'],
    'skipped'          => $results['skipped'],
    'not_found_emails' => $not_found_emails,
]);
