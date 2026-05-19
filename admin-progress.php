<?php
// ── Mật khẩu bảo vệ trang ───────────────────────────────────────────────────
define('ADMIN_PASS', 'rni2025');

session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASS) {
        $_SESSION['rni_admin'] = true;
    } else {
        $login_error = true;
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
if (empty($_SESSION['rni_admin'])) {
    ?><!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Đăng nhập – RNI Admin</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh}
  .box{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);width:100%;max-width:360px}
  h2{text-align:center;margin-bottom:24px;color:#1a202c}
  input[type=password]{width:100%;padding:10px 14px;border:1px solid #cbd5e0;border-radius:8px;font-size:15px;margin-bottom:12px}
  button{width:100%;padding:11px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:15px;cursor:pointer}
  button:hover{background:#4338ca}
  .err{color:#e53e3e;font-size:13px;margin-bottom:10px;text-align:center}
</style>
</head>
<body>
<div class="box">
  <h2>🔐 RNI Admin</h2>
  <?php if (!empty($login_error)): ?><p class="err">Mật khẩu không đúng</p><?php endif; ?>
  <form method="post">
    <input type="password" name="password" placeholder="Nhập mật khẩu" autofocus/>
    <button type="submit">Đăng nhập</button>
  </form>
</div>
</body></html><?php
    exit;
}

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
    die('<p style="color:red;padding:20px">Không kết nối được database</p>');
}

// ── Bộ lọc ───────────────────────────────────────────────────────────────────
$filter_email  = trim($_GET['email']  ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_event  = trim($_GET['event']  ?? '');
$filter_date   = trim($_GET['date']   ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

$where = ['1=1'];
$params = [];
if ($filter_email)  { $where[] = 'email LIKE ?';       $params[] = "%{$filter_email}%"; }
if ($filter_course) { $where[] = 'course_name LIKE ?'; $params[] = "%{$filter_course}%"; }
if ($filter_event)  { $where[] = 'event = ?';          $params[] = $filter_event; }
if ($filter_date)   { $where[] = 'DATE(created_at) = ?'; $params[] = $filter_date; }
$where_sql = implode(' AND ', $where);

// Tổng số bản ghi
$total = $pdo->prepare("SELECT COUNT(*) FROM user_progress_v2 WHERE {$where_sql}");
$total->execute($params);
$total_rows = (int) $total->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));
$offset = ($page - 1) * $per_page;

// Dữ liệu trang hiện tại
$stmt = $pdo->prepare("SELECT * FROM user_progress_v2 WHERE {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Thống kê tổng quan
$stats = $pdo->query("
    SELECT
        COUNT(DISTINCT email) AS total_students,
        SUM(event='session_start') AS total_sessions,
        SUM(event='lesson_complete') AS total_completes,
        SUM(event='lesson_open') AS total_opens
    FROM user_progress_v2
")->fetch();

// Danh sách học viên duy nhất để autocomplete
$emails = $pdo->query("SELECT DISTINCT email FROM user_progress_v2 ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function q($k, $v = null) {
    $p = $_GET;
    if ($v === null) unset($p[$k]); else $p[$k] = $v;
    unset($p['page']);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Tiến độ học viên – RNI</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#f7f8fc;color:#1a202c;font-size:14px}
header{background:#4f46e5;color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between}
header h1{font-size:18px;font-weight:600}
header form button{background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px}
header form button:hover{background:rgba(255,255,255,.35)}
.stats{display:flex;gap:16px;padding:20px 24px;flex-wrap:wrap}
.stat{background:#fff;border-radius:10px;padding:16px 22px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.stat .num{font-size:28px;font-weight:700;color:#4f46e5}
.stat .lbl{font-size:12px;color:#718096;margin-top:2px}
.filters{background:#fff;margin:0 24px 16px;padding:14px 18px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.filters label{font-size:12px;color:#718096;display:block;margin-bottom:4px}
.filters input,.filters select{padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;min-width:160px}
.filters button{padding:8px 18px;background:#4f46e5;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px}
.filters button:hover{background:#4338ca}
.filters a.reset{padding:8px 14px;background:#e2e8f0;color:#4a5568;border-radius:7px;text-decoration:none;font-size:13px}
.wrap{padding:0 24px 24px;overflow-x:auto}
table{width:100%;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-collapse:collapse;overflow:hidden}
th{background:#f1f5f9;padding:10px 14px;text-align:left;font-size:12px;color:#718096;font-weight:600;white-space:nowrap}
td{padding:10px 14px;border-top:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#fafbff}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600}
.badge.session_start{background:#ebf8ff;color:#2b6cb0}
.badge.lesson_open{background:#fefcbf;color:#744210}
.badge.lesson_complete{background:#f0fff4;color:#276749}
.badge.unknown{background:#f7fafc;color:#a0aec0}
.pct-bar{display:flex;align-items:center;gap:8px}
.pct-bar .bar{flex:1;height:7px;background:#e2e8f0;border-radius:4px;min-width:60px}
.pct-bar .fill{height:100%;border-radius:4px;background:#4f46e5}
.pct-bar span{font-size:12px;color:#4a5568;white-space:nowrap}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:18px;flex-wrap:wrap}
.pagination a,.pagination span{padding:6px 12px;border-radius:7px;text-decoration:none;font-size:13px}
.pagination a{background:#fff;border:1px solid #e2e8f0;color:#4a5568}
.pagination a:hover{background:#4f46e5;color:#fff;border-color:#4f46e5}
.pagination span{background:#4f46e5;color:#fff}
.empty{text-align:center;padding:40px;color:#a0aec0}
@media(max-width:600px){.stats{padding:14px}.stat .num{font-size:22px}.filters{gap:8px}.wrap{padding:0 12px 20px}.stats{padding:14px 12px}}
</style>
</head>
<body>

<header>
  <h1>📊 Tiến độ học viên – RNI</h1>
  <form method="post"><button name="logout" value="1">Đăng xuất</button></form>
</header>

<!-- Thống kê tổng quan -->
<div class="stats">
  <div class="stat"><div class="num"><?= number_format($stats['total_students']) ?></div><div class="lbl">Học viên</div></div>
  <div class="stat"><div class="num"><?= number_format($stats['total_sessions']) ?></div><div class="lbl">Lần mở app</div></div>
  <div class="stat"><div class="num"><?= number_format($stats['total_opens']) ?></div><div class="lbl">Bài học đã mở</div></div>
  <div class="stat"><div class="num"><?= number_format($stats['total_completes']) ?></div><div class="lbl">Bài học hoàn thành</div></div>
</div>

<!-- Bộ lọc -->
<form method="get" class="filters">
  <div>
    <label>Email học viên</label>
    <input list="emails" name="email" value="<?= h($filter_email) ?>" placeholder="Tìm theo email…"/>
    <datalist id="emails"><?php foreach ($emails as $e): ?><option value="<?= h($e) ?>"><?php endforeach; ?></datalist>
  </div>
  <div>
    <label>Khóa học</label>
    <select name="course">
      <option value="">— Tất cả —</option>
      <?php foreach (['R','N','I'] as $c): ?>
      <option value="<?= $c ?>" <?= $filter_course === $c ? 'selected' : '' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Sự kiện</label>
    <select name="event">
      <option value="">— Tất cả —</option>
      <option value="session_start"   <?= $filter_event==='session_start'   ? 'selected':'' ?>>Mở app</option>
      <option value="lesson_open"     <?= $filter_event==='lesson_open'     ? 'selected':'' ?>>Mở bài học</option>
      <option value="lesson_complete" <?= $filter_event==='lesson_complete' ? 'selected':'' ?>>Hoàn thành bài</option>
    </select>
  </div>
  <div>
    <label>Ngày</label>
    <input type="date" name="date" value="<?= h($filter_date) ?>"/>
  </div>
  <button type="submit">Lọc</button>
  <a class="reset" href="<?= $_SERVER['PHP_SELF'] ?>">Xóa lọc</a>
</form>

<!-- Bảng dữ liệu -->
<div class="wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Thời gian</th>
        <th>Email</th>
        <th>Tên</th>
        <th>Khóa</th>
        <th>Sự kiện</th>
        <th>Bài học</th>
        <th>Tiến độ</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="empty">Không có dữ liệu</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td style="color:#a0aec0"><?= $r['id'] ?></td>
        <td style="white-space:nowrap"><?= h($r['created_at']) ?></td>
        <td><?= h($r['email']) ?></td>
        <td><?= h($r['name']) ?></td>
        <td style="font-weight:600"><?= h($r['course_id']) ?></td>
        <td><span class="badge <?= h($r['event']) ?>"><?= h($r['event']) ?></span></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['lesson_title']) ?>"><?= h($r['lesson_title']) ?: '—' ?></td>
        <td>
          <?php if ($r['total_count'] > 0): ?>
          <div class="pct-bar">
            <div class="bar"><div class="fill" style="width:<?= $r['progress_pct'] ?>%"></div></div>
            <span><?= $r['completed_count'] ?>/<?= $r['total_count'] ?> (<?= $r['progress_pct'] ?>%)</span>
          </div>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <!-- Phân trang -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= q('page', $page-1) ?>">‹ Trước</a><?php endif; ?>
    <?php
    $start = max(1, $page-2); $end = min($total_pages, $page+2);
    for ($i = $start; $i <= $end; $i++):
    ?>
      <?php if ($i === $page): ?>
        <span><?= $i ?></span>
      <?php else: ?>
        <a href="<?= q('page', $i) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?><a href="<?= q('page', $page+1) ?>">Tiếp ›</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <p style="text-align:center;color:#a0aec0;font-size:12px;margin-top:12px">
    <?= number_format($total_rows) ?> bản ghi
    <?php if ($total_pages > 1): ?> · Trang <?= $page ?>/<?= $total_pages ?><?php endif; ?>
  </p>
</div>

</body>
</html>
