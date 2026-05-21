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
// Lấy API key tại: Kajabi → Settings → Integrations → API
define('KAJABI_API_KEY',  'YOUR_KAJABI_API_KEY_HERE');
define('KAJABI_BASE_URL', 'https://kajabi.com/api/v1');

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

// ── Hàm gọi Kajabi API ───────────────────────────────────────────────────────
function kajabi_request($method, $path, $body = null) {
    $url = KAJABI_BASE_URL . $path;
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Kajabi-Key: ' . KAJABI_API_KEY,
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
function kajabi_find_person($email) {
    $res = kajabi_request('GET', '/people?email=' . urlencode($email));
    if ($res['code'] === 200 && !empty($res['body']['people'][0])) {
        return $res['body']['people'][0];
    }
    return null;
}

// Gán tags cho person (thêm vào, không xoá tags cũ)
function kajabi_add_tags($person_id, array $tags) {
    return kajabi_request('POST', "/people/{$person_id}/tags", ['tags' => $tags]);
}

// Xoá các tag RNI cũ trước khi gán lại (để tránh tag cũ tích luỹ)
function kajabi_remove_rni_tags($person_id) {
    $all_rni_tags = [
        'rni-xong-quiz-chua-hoc', 'rni-dang-hoc', 'rni-hoan-thanh',
        'rni-inactive-1d', 'rni-inactive-2d', 'rni-inactive-4d', 'rni-inactive-7d',
    ];
    return kajabi_request('DELETE', "/people/{$person_id}/tags", ['tags' => $all_rni_tags]);
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
    $person = kajabi_find_person($email);
    if (!$person) {
        $results['not_found']++;
        $not_found_emails[] = $email;
        continue;
    }

    $person_id = $person['id'];

    // Xoá tags RNI cũ
    kajabi_remove_rni_tags($person_id);

    // Tính tags mới
    $group = get_group($u);
    $new_tags = ['rni-' . str_replace('_', '-', $group)];
    $inactive_tags = get_inactive_tags($u['last_activity']);
    $new_tags = array_merge($new_tags, $inactive_tags);

    // Gán tags mới
    $res = kajabi_add_tags($person_id, $new_tags);
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
