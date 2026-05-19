<?php
/**
 * RNI – Theo dõi tiến độ học viên
 * Upload file này vào thư mục gốc (public_html) của thuvien.rni.vn
 *
 * Tạo bảng MySQL lần đầu bằng câu SQL sau (chạy trong phpMyAdmin):
 *
 * CREATE TABLE IF NOT EXISTS user_progress_v2 (
 *   id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   email        VARCHAR(255) NOT NULL,
 *   name         VARCHAR(255) DEFAULT '',
 *   phone        VARCHAR(20)  DEFAULT '',
 *   course_id    VARCHAR(10)  DEFAULT '',
 *   course_name  VARCHAR(255) DEFAULT '',
 *   event        VARCHAR(50)  DEFAULT '',   -- session_start | lesson_open | lesson_complete
 *   lesson_id    VARCHAR(100) DEFAULT '',
 *   lesson_title VARCHAR(500) DEFAULT '',
 *   completed_count TINYINT UNSIGNED DEFAULT 0,
 *   total_count     TINYINT UNSIGNED DEFAULT 0,
 *   progress_pct    TINYINT UNSIGNED DEFAULT 0,
 *   created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   INDEX idx_email      (email),
 *   INDEX idx_event      (event),
 *   INDEX idx_created_at (created_at)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * Nếu bảng đã tồn tại (chưa có cột phone), chạy thêm câu này:
 * ALTER TABLE user_progress_v2
 *   ADD COLUMN phone VARCHAR(20) DEFAULT '' AFTER name;
 */

// ── CORS ────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── GET: Lấy danh sách bài đã hoàn thành theo email ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $email = mb_substr(trim($_GET['email'] ?? ''), 0, 255);
    if (!$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu email']);
        exit;
    }

    $db_host = 'localhost';
    $db_name = 'rni_courses_quiz_RNI_DTVNBT';
    $db_user = 'rni_quiz_user';
    $db_pass = 'RNI-quiz-dtvnbt';

    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare("
            SELECT DISTINCT lesson_id
            FROM user_progress_v2
            WHERE email = ? AND event = 'lesson_complete' AND lesson_id != ''
        ");
        $stmt->execute([$email]);
        $lessons = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['completedLessons' => $lessons]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi truy vấn']);
        error_log('[RNI progress] GET error: ' . $e->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Đọc body JSON ────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || empty($input['email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Thiếu trường bắt buộc: email']);
    exit;
}

// ── Kết nối MySQL ────────────────────────────────────────────────────────────
$db_host = 'localhost';
$db_name = 'rni_courses_quiz_RNI_DTVNBT';
$db_user = 'rni_quiz_user';
$db_pass = 'RNI-quiz-dtvnbt';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Không kết nối được database']);
    error_log('[RNI progress] DB connect error: ' . $e->getMessage());
    exit;
}

// ── Ghi bản ghi ─────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        INSERT INTO user_progress_v2
            (email, name, phone, course_id, course_name, event,
             lesson_id, lesson_title, completed_count, total_count, progress_pct)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name           = VALUES(name),
            phone          = VALUES(phone),
            course_id      = VALUES(course_id),
            course_name    = VALUES(course_name),
            event          = VALUES(event),
            lesson_id      = VALUES(lesson_id),
            lesson_title   = VALUES(lesson_title),
            completed_count = VALUES(completed_count),
            total_count    = VALUES(total_count),
            progress_pct   = VALUES(progress_pct)
    ");

    $stmt->execute([
        mb_substr(trim($input['email']       ?? ''), 0, 255),
        mb_substr(trim($input['name']        ?? ''), 0, 255),
        mb_substr(trim($input['phone']       ?? ''), 0, 20),
        mb_substr(trim($input['courseId']    ?? ''), 0, 10),
        mb_substr(trim($input['courseName']  ?? ''), 0, 255),
        mb_substr(trim($input['event']       ?? 'unknown'), 0, 50),
        mb_substr(trim($input['lessonId']    ?? ''), 0, 100),
        mb_substr(trim($input['lessonTitle'] ?? ''), 0, 500),
        (int) ($input['completedCount'] ?? 0),
        (int) ($input['totalCount']     ?? 0),
        (int) ($input['progressPct']    ?? 0),
    ]);

    echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Lỗi ghi dữ liệu', 'detail' => $e->getMessage()]);
    error_log('[RNI progress] DB insert error: ' . $e->getMessage());
}
