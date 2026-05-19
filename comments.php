<?php
/**
 * RNI – Hệ thống bình luận bài học
 * Upload file này vào thư mục gốc (public_html) của thuvien.rni.vn
 *
 * Tạo bảng MySQL lần đầu bằng cách gọi: POST {"action":"setup"}
 * hoặc chạy trực tiếp trong phpMyAdmin:
 *
 * CREATE TABLE IF NOT EXISTS lesson_comments (
 *   id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *   lesson_id   VARCHAR(100) NOT NULL,
 *   parent_id   INT UNSIGNED DEFAULT NULL,
 *   user_email  VARCHAR(255) NOT NULL,
 *   user_name   VARCHAR(255) NOT NULL,
 *   content     TEXT NOT NULL,
 *   likes       INT UNSIGNED DEFAULT 0,
 *   created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   INDEX idx_lesson (lesson_id, parent_id),
 *   INDEX idx_parent (parent_id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * CREATE TABLE IF NOT EXISTS comment_likes (
 *   comment_id  INT UNSIGNED NOT NULL,
 *   user_email  VARCHAR(255) NOT NULL,
 *   PRIMARY KEY (comment_id, user_email)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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

// ── DB ──────────────────────────────────────────────────────────────────────
$db_host = 'localhost';
$db_name = 'rni_courses_quiz_RNI_DTVNBT';
$db_user = 'rni_quiz_user';
$db_pass = 'RNI-quiz-dtvnbt';

function get_pdo($host, $name, $user, $pass) {
    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

// ── GET: Lấy danh sách bình luận ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lesson_id  = mb_substr(trim($_GET['lesson_id'] ?? ''), 0, 100);
    $page       = max(1, (int) ($_GET['page'] ?? 1));
    $per_page   = min(50, max(1, (int) ($_GET['per_page'] ?? 10)));
    $user_email = mb_substr(trim($_GET['user_email'] ?? ''), 0, 255);

    if (!$lesson_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu lesson_id']);
        exit;
    }

    try {
        $pdo = get_pdo($db_host, $db_name, $db_user, $db_pass);
        $offset = ($page - 1) * $per_page;

        // Tổng số comment gốc (không tính reply)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM lesson_comments WHERE lesson_id=? AND parent_id IS NULL"
        );
        $stmt->execute([$lesson_id]);
        $total = (int) $stmt->fetchColumn();

        // Lấy comment gốc theo trang
        $stmt = $pdo->prepare(
            "SELECT id, user_name, content, likes, created_at
             FROM lesson_comments
             WHERE lesson_id=? AND parent_id IS NULL
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$lesson_id, $per_page, $offset]);
        $comments = $stmt->fetchAll();

        if (empty($comments)) {
            echo json_encode(['total' => $total, 'comments' => [], 'liked_ids' => []]);
            exit;
        }

        $comment_ids = array_column($comments, 'id');
        $in_placeholders = implode(',', array_fill(0, count($comment_ids), '?'));

        // Lấy tất cả replies cho các comment trong trang này
        $stmt = $pdo->prepare(
            "SELECT id, parent_id, user_name, content, likes, created_at
             FROM lesson_comments
             WHERE parent_id IN ({$in_placeholders})
             ORDER BY created_at ASC"
        );
        $stmt->execute($comment_ids);
        $all_replies = $stmt->fetchAll();

        // Gom replies theo parent_id
        $replies_map = [];
        foreach ($all_replies as $r) {
            $replies_map[$r['parent_id']][] = $r;
        }
        foreach ($comments as &$c) {
            $c['replies'] = $replies_map[$c['id']] ?? [];
        }
        unset($c);

        // Lấy những comment user đã like (nếu có email)
        $liked_ids = [];
        if ($user_email) {
            $all_ids = array_merge(
                $comment_ids,
                array_column($all_replies, 'id')
            );
            if ($all_ids) {
                $in2 = implode(',', array_fill(0, count($all_ids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT comment_id FROM comment_likes WHERE user_email=? AND comment_id IN ({$in2})"
                );
                $stmt->execute(array_merge([$user_email], $all_ids));
                $liked_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $liked_ids = array_map('intval', $liked_ids);
            }
        }

        echo json_encode([
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'comments'  => $comments,
            'liked_ids' => $liked_ids,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi truy vấn']);
        error_log('[RNI comments] GET error: ' . $e->getMessage());
    }
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Body JSON không hợp lệ']);
    exit;
}

$action = $input['action'] ?? '';

try {
    $pdo = get_pdo($db_host, $db_name, $db_user, $db_pass);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Không kết nối được database']);
    error_log('[RNI comments] DB connect error: ' . $e->getMessage());
    exit;
}

// ── action: setup — tạo bảng ─────────────────────────────────────────────────
if ($action === 'setup') {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lesson_comments (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                lesson_id  VARCHAR(100) NOT NULL,
                parent_id  INT UNSIGNED DEFAULT NULL,
                user_email VARCHAR(255) NOT NULL,
                user_name  VARCHAR(255) NOT NULL,
                content    TEXT NOT NULL,
                likes      INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lesson (lesson_id, parent_id),
                INDEX idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comment_likes (
                comment_id INT UNSIGNED NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                PRIMARY KEY (comment_id, user_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo json_encode(['success' => true, 'message' => 'Đã tạo bảng thành công']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── action: add — thêm bình luận hoặc reply ──────────────────────────────────
if ($action === 'add') {
    $lesson_id  = mb_substr(trim($input['lesson_id']  ?? ''), 0, 100);
    $user_email = mb_substr(trim($input['user_email'] ?? ''), 0, 255);
    $user_name  = mb_substr(trim($input['user_name']  ?? 'Học viên'), 0, 255);
    $content    = mb_substr(trim($input['content']    ?? ''), 0, 2000);
    $parent_id  = isset($input['parent_id']) ? (int) $input['parent_id'] : null;

    if (!$lesson_id || !$user_email || !$content) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu trường bắt buộc']);
        exit;
    }

    // Xác nhận parent_id hợp lệ
    if ($parent_id) {
        $stmt = $pdo->prepare("SELECT id, parent_id FROM lesson_comments WHERE id=?");
        $stmt->execute([$parent_id]);
        $parent = $stmt->fetch();
        if (!$parent || $parent['parent_id'] !== null) {
            // Chỉ cho phép 1 cấp reply, không cho reply của reply
            $parent_id = $parent ? (int) $parent['parent_id'] ?: $parent_id : null;
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO lesson_comments (lesson_id, parent_id, user_email, user_name, content)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$lesson_id, $parent_id, $user_email, $user_name, $content]);
        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi ghi dữ liệu']);
        error_log('[RNI comments] INSERT error: ' . $e->getMessage());
    }
    exit;
}

// ── action: like — toggle like ───────────────────────────────────────────────
if ($action === 'like') {
    $comment_id = (int) ($input['comment_id'] ?? 0);
    $user_email = mb_substr(trim($input['user_email'] ?? ''), 0, 255);

    if (!$comment_id || !$user_email) {
        http_response_code(400);
        echo json_encode(['error' => 'Thiếu comment_id hoặc user_email']);
        exit;
    }

    try {
        // Kiểm tra đã like chưa
        $stmt = $pdo->prepare(
            "SELECT 1 FROM comment_likes WHERE comment_id=? AND user_email=?"
        );
        $stmt->execute([$comment_id, $user_email]);
        $already_liked = (bool) $stmt->fetchColumn();

        if ($already_liked) {
            $pdo->prepare("DELETE FROM comment_likes WHERE comment_id=? AND user_email=?")
                ->execute([$comment_id, $user_email]);
            $pdo->prepare("UPDATE lesson_comments SET likes=GREATEST(0,likes-1) WHERE id=?")
                ->execute([$comment_id]);
            echo json_encode(['success' => true, 'action' => 'unliked']);
        } else {
            $pdo->prepare("INSERT IGNORE INTO comment_likes (comment_id, user_email) VALUES (?,?)")
                ->execute([$comment_id, $user_email]);
            $pdo->prepare("UPDATE lesson_comments SET likes=likes+1 WHERE id=?")
                ->execute([$comment_id]);
            echo json_encode(['success' => true, 'action' => 'liked']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Lỗi xử lý like']);
        error_log('[RNI comments] LIKE error: ' . $e->getMessage());
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action không hợp lệ']);
