<?php
// ── Mật khẩu bảo vệ trang ───────────────────────────────────────────────────
define('ADMIN_PASS', 'rni2025');
define('COOKIE_NAME', 'rni_admin_v1');
define('COOKIE_TOKEN', hash('sha256', ADMIN_PASS . 'rni_salt_2025'));

// Đăng xuất
if (isset($_POST['logout'])) {
    setcookie(COOKIE_NAME, '', time() - 3600, '/');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Đăng nhập
$login_error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASS) {
        setcookie(COOKIE_NAME, COOKIE_TOKEN, time() + 86400 * 7, '/'); // 7 ngày
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $login_error = true;
}

// Kiểm tra đã đăng nhập chưa
$logged_in = isset($_COOKIE[COOKIE_NAME]) && $_COOKIE[COOKIE_NAME] === COOKIE_TOKEN;

// Export CSV nhóm học viên
if ($logged_in && isset($_GET['export']) && $_GET['export'] === 'csv_groups') {
    $db_host='localhost';$db_name='rni_courses_quiz_RNI_DTVNBT';$db_user='rni_quiz_user';$db_pass='RNI-quiz-dtvnbt';
    try { $pdo_e=new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); } catch(PDOException $e){die('Loi DB');}
    $fc=trim($_GET['course']??'');$fg=trim($_GET['group']??'');$fd=(int)($_GET['days']??0);
    $gw=['1=1'];$gp=[];
    if($fc){$gw[]='course_id=?';$gp[]=$fc;}
    $q=$pdo_e->prepare("SELECT email,MAX(name) as name,MAX(CASE WHEN phone!='' THEN phone END) as phone,MAX(course_id) as course_id,MAX(progress_pct) as max_pct,MAX(completed_count) as max_completed,MAX(CASE WHEN total_count>0 THEN total_count END) as total_count,MAX(created_at) as last_activity,SUM(event='quiz_complete') as has_quiz,SUM(event IN ('lesson_open','lesson_complete')) as has_lesson FROM user_progress_v2 WHERE ".implode(' AND ',$gw)." GROUP BY email ORDER BY last_activity DESC");
    $q->execute($gp);$users=$q->fetchAll();
    $fn=function($r){$pct=(int)$r['max_pct'];$days=(time()-strtotime($r['last_activity']))/86400;if($pct>=100)return'hoan_thanh';if((int)$r['has_lesson']>0&&$pct>0&&$days<=7)return'dang_hoc';if((int)$r['has_lesson']>0&&$pct>0&&$days>7)return'bo_do';return'chua_hoc';};
    $labels=['chua_hoc'=>'Xong quiz, chua hoc','dang_hoc'=>'Dang hoc','bo_do'=>'Bo do','hoan_thanh'=>'Hoan thanh'];
    $users=array_values(array_filter($users,function($u)use($fg,$fd,$fn){
        if($fg&&$fn($u)!==$fg)return false;
        if($fd>0&&(time()-strtotime($u['last_activity']))/86400<$fd)return false;
        return true;
    }));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="phan-nhom-hoc-vien-'.date('Ymd-His').'.csv"');
    $out=fopen('php://output','w');fprintf($out,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Email','Ten','SDT','Khoa','Nhom','Tien do (%)','Da xong','Tong bai','Hoat dong cuoi']);
    foreach($users as $u){fputcsv($out,[$u['email'],$u['name']??'',$u['phone']??'',$u['course_id']??'',$labels[$fn($u)]??'',(int)$u['max_pct'],(int)$u['max_completed'],(int)$u['total_count'],$u['last_activity']]);}
    fclose($out);exit;
}

// Export CSV
if ($logged_in && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $db_host = 'localhost';
    $db_name = 'rni_courses_quiz_RNI_DTVNBT';
    $db_user = 'rni_quiz_user';
    $db_pass = 'RNI-quiz-dtvnbt';
    try {
        $pdo_e = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) { die('Loi DB'); }

    $fe = trim($_GET['email']  ?? '');
    $fc = trim($_GET['course'] ?? '');
    $fv = trim($_GET['event']  ?? '');
    $fd = trim($_GET['date']   ?? '');
    $ew = ['1=1']; $ep = [];
    if ($fe) { $ew[] = 'email LIKE ?';         $ep[] = "%{$fe}%"; }
    if ($fc) { $ew[] = 'course_id = ?';        $ep[] = $fc; }
    if ($fv) { $ew[] = 'event = ?';            $ep[] = $fv; }
    if ($fd) { $ew[] = 'DATE(created_at) = ?'; $ep[] = $fd; }
    $eq = $pdo_e->prepare("SELECT * FROM user_progress_v2 WHERE " . implode(' AND ', $ew) . " ORDER BY id DESC");
    $eq->execute($ep);
    $data = $eq->fetchAll();

    $filename = 'tien-do-hoc-vien-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 để Excel đọc được tiếng Việt
    fputcsv($out, ['#','Thoi gian','Email','Ten','SDT','Khoa ID','Khoa hoc','Su kien','Bai hoc ID','Bai hoc','So bai xong','Tong so bai','% hoan thanh']);
    foreach ($data as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['created_at'] ?? '',
            $r['email'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['course_id'] ?? '',
            $r['course_name'] ?? '',
            $r['event'] ?? '',
            $r['lesson_id'] ?? '',
            $r['lesson_title'] ?? '',
            $r['completed_count'] ?? '',
            $r['total_count'] ?? '',
            $r['progress_pct'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

if (!$logged_in) {
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
h2{text-align:center;margin-bottom:24px;color:#1a202c;font-size:20px}
input[type=password]{width:100%;padding:10px 14px;border:1px solid #cbd5e0;border-radius:8px;font-size:15px;margin-bottom:12px;outline:none}
input[type=password]:focus{border-color:#4f46e5}
button{width:100%;padding:11px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:15px;cursor:pointer}
button:hover{background:#4338ca}
.err{color:#e53e3e;font-size:13px;margin-bottom:10px;text-align:center;padding:8px;background:#fff5f5;border-radius:6px}
</style>
</head>
<body>
<div class="box">
  <h2>RNI Admin</h2>
  <?php if ($login_error): ?><p class="err">Mat khau khong dung</p><?php endif; ?>
  <form method="post">
    <input type="password" name="password" placeholder="Nhap mat khau..." autofocus/>
    <button type="submit">Dang nhap</button>
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
    showError('Khong ket noi duoc database. Kiem tra lai thong tin MySQL trong file.');
    exit;
}

// Kiểm tra bảng tồn tại + phát hiện cột
try {
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM user_progress_v2") as $col) {
        $cols[] = $col['Field'];
    }
} catch (PDOException $e) {
    showError('Bang <b>user_progress_v2</b> chua duoc tao. Vao phpMyAdmin va chay lenh SQL tao bang truoc.');
    exit;
}
$has_event   = in_array('event',        $cols);
$has_course  = in_array('course_id',    $cols);
$has_lesson  = in_array('lesson_title', $cols);
$has_pct     = in_array('progress_pct', $cols);
$has_phone   = in_array('phone',        $cols);

// ── Chế độ xem ───────────────────────────────────────────────────────────────
$view = $_GET['view'] ?? 'events'; // events | groups

// ── Bộ lọc ───────────────────────────────────────────────────────────────────
$filter_email  = trim($_GET['email']  ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_event  = trim($_GET['event']  ?? '');
$filter_date   = trim($_GET['date']   ?? '');
$filter_group  = trim($_GET['group']  ?? '');
$filter_days   = (int)($_GET['days']   ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

$where  = ['1=1'];
$params = [];
if ($filter_email)                   { $where[] = 'email LIKE ?';         $params[] = "%{$filter_email}%"; }
if ($filter_course && $has_course)   { $where[] = 'course_id = ?';        $params[] = $filter_course; }
if ($filter_event  && $has_event)    { $where[] = 'event = ?';            $params[] = $filter_event; }
if ($filter_date)                    { $where[] = 'DATE(created_at) = ?'; $params[] = $filter_date; }
$wsql = implode(' AND ', $where);

try {
    $q = $pdo->prepare("SELECT COUNT(*) FROM user_progress_v2 WHERE {$wsql}");
    $q->execute($params);
    $total_rows  = (int) $q->fetchColumn();
    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    $offset      = ($page - 1) * $per_page;

    $q2 = $pdo->prepare("SELECT * FROM user_progress_v2 WHERE {$wsql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}");
    $q2->execute($params);
    $rows = $q2->fetchAll();

    if ($has_event) {
        $stats = $pdo->query("
            SELECT
                COUNT(*)                                    AS total_students,
                SUM(event = 'lesson_complete')              AS total_completes,
                SUM(completed_count > 0)                    AS total_started,
                ROUND(AVG(NULLIF(progress_pct, 0)))         AS avg_pct
            FROM user_progress_v2
        ")->fetch();
    } else {
        $stats = $pdo->query("
            SELECT COUNT(*) AS total_students, 0 AS total_completes,
                   0 AS total_started, 0 AS avg_pct
            FROM user_progress_v2
        ")->fetch();
    }

    $emails = $pdo->query("SELECT DISTINCT email FROM user_progress_v2 ORDER BY email LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

    // ── Phân nhóm học viên ────────────────────────────────────────────────────
    $gw = ['1=1']; $gp = [];
    if ($filter_course) { $gw[] = 'course_id = ?'; $gp[] = $filter_course; }
    $gwsql = implode(' AND ', $gw);
    $group_rows = $pdo->prepare("
        SELECT email,
               MAX(name) as name,
               MAX(CASE WHEN phone != '' THEN phone END) as phone,
               MAX(course_id) as course_id,
               MAX(progress_pct) as max_pct,
               MAX(completed_count) as max_completed,
               MAX(CASE WHEN total_count > 0 THEN total_count END) as total_count,
               MAX(created_at) as last_activity,
               SUM(event='quiz_complete') as has_quiz,
               SUM(event IN ('lesson_open','lesson_complete')) as has_lesson
        FROM user_progress_v2
        WHERE {$gwsql}
        GROUP BY email
        ORDER BY last_activity DESC
    ");
    $group_rows->execute($gp);
    $all_users = $group_rows->fetchAll();

    $fn_group = function($r) {
        $pct = (int)$r['max_pct'];
        if ($pct >= 100)                              return 'hoan_thanh';
        if ((int)$r['has_lesson'] > 0 && $pct > 0)   return 'dang_hoc';
        return 'chua_hoc';
    };
    $group_labels = [
        'chua_hoc'   => 'Xong quiz, chưa học',
        'dang_hoc'   => 'Đang học',
        'hoan_thanh' => 'Hoàn thành',
    ];
    $group_colors = [
        'chua_hoc'   => ['bg'=>'#faf5ff','color'=>'#553c9a'],
        'dang_hoc'   => ['bg'=>'#ebf8ff','color'=>'#2b6cb0'],
        'hoan_thanh' => ['bg'=>'#f0fff4','color'=>'#276749'],
    ];
    $group_counts = array_fill_keys(array_keys($group_labels), 0);
    foreach ($all_users as $u) $group_counts[$fn_group($u)]++;

    $filtered_users = array_values(array_filter($all_users, function($u) use ($filter_group, $filter_days, $fn_group) {
        if ($filter_group && $fn_group($u) !== $filter_group) return false;
        if ($filter_days > 0) {
            $days = (time() - strtotime($u['last_activity'])) / 86400;
            if ($days < $filter_days) return false;
        }
        return true;
    }));

} catch (PDOException $e) {
    showError('Loi truy van du lieu: ' . htmlspecialchars($e->getMessage()));
    exit;
}

function showError($msg) { ?>
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"/><title>Loi</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff5f5}
.box{background:#fff;border:1px solid #fed7d7;border-radius:10px;padding:30px 36px;max-width:500px;text-align:center}
h3{color:#c53030;margin-bottom:12px}p{color:#4a5568;line-height:1.6}</style></head>
<body><div class="box"><h3>Co loi xay ra</h3><p><?= $msg ?></p></div></body></html><?php
}

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function qurl($k, $v = null) {
    $p = $_GET;
    if ($v === null) unset($p[$k]); else $p[$k] = $v;
    if ($k !== 'page') unset($p['page']);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Tien do hoc vien - RNI</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#f7f8fc;color:#1a202c;font-size:14px}
header{background:#4f46e5;color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px}
header h1{font-size:17px;font-weight:600}
header form button{background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px}
header form button:hover{background:rgba(255,255,255,.35)}
.stats{display:flex;gap:14px;padding:18px 24px;flex-wrap:wrap}
.stat{background:#fff;border-radius:10px;padding:14px 20px;flex:1;min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.stat .num{font-size:26px;font-weight:700;color:#4f46e5}
.stat .lbl{font-size:12px;color:#718096;margin-top:2px}
.filters{background:#fff;margin:0 24px 14px;padding:14px 18px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.filters label{font-size:12px;color:#718096;display:block;margin-bottom:4px}
.filters input,.filters select{padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;min-width:150px}
.filters input:focus,.filters select:focus{outline:none;border-color:#4f46e5}
.filters button{padding:8px 18px;background:#4f46e5;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px}
.filters button:hover{background:#4338ca}
.filters a.reset{padding:8px 14px;background:#e2e8f0;color:#4a5568;border-radius:7px;text-decoration:none;font-size:13px}
.filters a.export{padding:8px 14px;background:#38a169;color:#fff;border-radius:7px;text-decoration:none;font-size:13px}
.filters a.export:hover{background:#2f855a}
.wrap{padding:0 24px 24px;overflow-x:auto}
table{width:100%;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-collapse:collapse}
th{background:#f1f5f9;padding:10px 14px;text-align:left;font-size:12px;color:#718096;font-weight:600;white-space:nowrap}
td{padding:9px 14px;border-top:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#fafbff}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
.ev-session_start{background:#ebf8ff;color:#2b6cb0}
.ev-lesson_open{background:#fefcbf;color:#744210}
.ev-lesson_complete{background:#f0fff4;color:#276749}
.ev-quiz_complete{background:#faf5ff;color:#553c9a}
.pct-bar{display:flex;align-items:center;gap:8px}
.pct-bar .bar{flex:1;height:6px;background:#e2e8f0;border-radius:4px;min-width:50px}
.pct-bar .fill{height:100%;border-radius:4px;background:#4f46e5}
.pct-bar span{font-size:12px;color:#4a5568;white-space:nowrap}
.pagination{display:flex;gap:5px;justify-content:center;margin-top:16px;flex-wrap:wrap}
.pagination a,.pagination span{padding:6px 12px;border-radius:7px;text-decoration:none;font-size:13px}
.pagination a{background:#fff;border:1px solid #e2e8f0;color:#4a5568}
.pagination a:hover{background:#4f46e5;color:#fff;border-color:#4f46e5}
.pagination span{background:#4f46e5;color:#fff}
.empty{text-align:center;padding:40px;color:#a0aec0}
.meta{text-align:center;color:#a0aec0;font-size:12px;margin-top:12px}
.tabs{display:flex;gap:4px;padding:14px 24px 0;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.tab{padding:9px 20px;border-radius:8px 8px 0 0;font-size:13px;font-weight:600;text-decoration:none;color:#718096;background:#f1f5f9;border:2px solid transparent;border-bottom:none;margin-bottom:-2px}
.tab.active{background:#fff;color:#4f46e5;border-color:#e2e8f0;border-bottom-color:#fff}
.tab:hover:not(.active){background:#e2e8f0}
.group-cards{display:flex;gap:12px;padding:18px 24px;flex-wrap:wrap}
.gc{background:#fff;border-radius:10px;padding:14px 20px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.08);cursor:pointer;border:2px solid transparent;text-decoration:none;display:block}
.gc:hover{border-color:#4f46e5}
.gc.active{border-color:#4f46e5;background:#fafbff}
.gc .num{font-size:24px;font-weight:700}
.gc .lbl{font-size:12px;color:#718096;margin-top:2px}
@media(max-width:600px){.stats,.filters{padding:12px}.wrap{padding:0 12px 20px}}
</style>
</head>
<body>

<header>
  <h1>Tien do hoc vien - RNI</h1>
  <form method="post"><button name="logout" value="1">Dang xuat</button></form>
</header>

<div class="tabs">
  <a href="?view=events" class="tab <?= $view==='events'?'active':'' ?>">📋 Nhật ký sự kiện</a>
  <a href="?view=groups" class="tab <?= $view==='groups'?'active':'' ?>">👥 Phân nhóm học viên</a>
</div>

<div class="stats">
  <div class="stat"><div class="num"><?= number_format((int)$stats['total_students']) ?></div><div class="lbl">Hoc vien</div></div>
  <div class="stat"><div class="num"><?= number_format((int)$stats['total_started']) ?></div><div class="lbl">Da hoc it nhat 1 bai</div></div>
  <div class="stat"><div class="num"><?= number_format((int)$stats['total_completes']) ?></div><div class="lbl">Dang hoan thanh bai</div></div>
  <div class="stat"><div class="num"><?= $stats['avg_pct'] ? $stats['avg_pct'].'%' : '—' ?></div><div class="lbl">Tien do trung binh</div></div>
</div>

<?php if ($view === 'groups'): ?>
<div class="group-cards">
  <?php foreach ($group_labels as $gk => $gl):
    $gc = $group_colors[$gk];
    $active = $filter_group === $gk ? 'active' : '';
    $href = $filter_group === $gk ? '?view=groups' : '?view=groups&group='.$gk.($filter_course?'&course='.h($filter_course):'');
  ?>
  <a class="gc <?= $active ?>" href="<?= $href ?>" style="border-left:4px solid <?= $gc['color'] ?>">
    <div class="num" style="color:<?= $gc['color'] ?>"><?= $group_counts[$gk] ?></div>
    <div class="lbl"><?= $gl ?></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="filters" style="margin-top:0">
  <div>
    <label>Khoá học</label>
    <select onchange="applyGroupFilter();" id="gf-course">
      <option value="">— Tất cả —</option>
      <?php foreach (['R','N','I'] as $c): ?>
      <option value="<?= $c ?>"<?= $filter_course===$c?' selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Không hoạt động</label>
    <select onchange="applyGroupFilter();" id="gf-days">
      <option value="0">— Tất cả —</option>
      <option value="2"<?= $filter_days===2?' selected':'' ?>>Hơn 2 ngày</option>
      <option value="4"<?= $filter_days===4?' selected':'' ?>>Hơn 4 ngày</option>
      <option value="7"<?= $filter_days===7?' selected':'' ?>>Hơn 7 ngày</option>
    </select>
  </div>
  <?php
    $eq2 = ['view'=>'groups','group'=>$filter_group,'course'=>$filter_course,'days'=>$filter_days,'export'=>'csv_groups'];
    $export_url2 = $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter($eq2, fn($v)=>$v!==''&&$v!==0));
  ?>
  <a class="export" href="<?= h($export_url2) ?>">⬇ Tải CSV nhóm này</a>
</div>
<script>
function applyGroupFilter(){
  var c=document.getElementById('gf-course').value;
  var d=document.getElementById('gf-days').value;
  var g='<?= h($filter_group) ?>';
  var u='?view=groups'+(g?'&group='+g:'')+(c?'&course='+c:'')+(d&&d!='0'?'&days='+d:'');
  location=u;
}
</script>

<div class="wrap">
<?php if (empty($filtered_users)): ?>
  <p class="empty">Không có học viên nào trong nhóm này</p>
<?php else: ?>
  <table>
    <thead><tr>
      <th>#</th><th>Email</th><th>Tên</th><th>SDT</th><th>Khoá</th>
      <th>Nhóm</th><th>Tiến độ</th><th>Hoạt động cuối</th>
    </tr></thead>
    <tbody>
    <?php foreach ($filtered_users as $i => $u):
      $gk = $fn_group($u);
      $gc = $group_colors[$gk];
      $gl = $group_labels[$gk];
      $pct = (int)$u['max_pct'];
      $done = (int)$u['max_completed'];
      $total = (int)$u['total_count'];
      $days = round((time() - strtotime($u['last_activity'])) / 86400);
      $days_lbl = $days === 0 ? 'Hôm nay' : ($days === 1 ? 'Hôm qua' : "{$days} ngày trước");
    ?>
    <tr>
      <td style="color:#a0aec0;font-size:12px"><?= $i+1 ?></td>
      <td><?= h($u['email']) ?></td>
      <td><?= h($u['name'] ?? '') ?></td>
      <td style="white-space:nowrap"><?= h($u['phone'] ?? '') ?: '—' ?></td>
      <td style="text-align:center;font-weight:600"><?= h($u['course_id'] ?? '') ?: '—' ?></td>
      <td><span class="badge" style="background:<?= $gc['bg'] ?>;color:<?= $gc['color'] ?>"><?= $gl ?></span></td>
      <td>
        <?php if ($total > 0): ?>
        <div class="pct-bar">
          <div class="bar"><div class="fill" style="width:<?= $pct ?>%"></div></div>
          <span><?= $done ?>/<?= $total ?> (<?= $pct ?>%)</span>
        </div>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="font-size:12px;color:#718096;white-space:nowrap"><?= h($days_lbl) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="meta"><?= count($filtered_users) ?> học viên<?= $filter_group ? ' — nhóm: '.$group_labels[$filter_group] : '' ?></p>
<?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="filters" <?= $view==='groups'?'style="display:none"':'' ?>>
  <div>
    <label>Email hoc vien</label>
    <input list="email-list" name="email" value="<?= h($filter_email) ?>" placeholder="Tim theo email..."/>
    <datalist id="email-list"><?php foreach ($emails as $e): ?><option value="<?= h($e) ?>"><?php endforeach; ?></datalist>
  </div>
  <div>
    <label>Khoa hoc</label>
    <select name="course">
      <option value="">— Tat ca —</option>
      <?php foreach (['R','N','I'] as $c): ?>
      <option value="<?= $c ?>"<?= $filter_course===$c?' selected':'' ?>><?= $c ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Su kien</label>
    <select name="event">
      <option value="">— Tat ca —</option>
      <option value="session_start"<?= $filter_event==='session_start'?' selected':'' ?>>Mo app</option>
      <option value="lesson_open"<?= $filter_event==='lesson_open'?' selected':'' ?>>Mo bai hoc</option>
      <option value="lesson_complete"<?= $filter_event==='lesson_complete'?' selected':'' ?>>Hoan thanh bai</option>
      <option value="quiz_complete"<?= $filter_event==='quiz_complete'?' selected':'' ?>>Ket qua quiz</option>
    </select>
  </div>
  <div>
    <label>Ngay</label>
    <input type="date" name="date" value="<?= h($filter_date) ?>"/>
  </div>
  <button type="submit">Loc</button>
  <a class="reset" href="<?= h($_SERVER['PHP_SELF']) ?>">Xoa loc</a>
  <?php
    $eq = $_GET; $eq['export'] = 'csv'; unset($eq['page']);
    $export_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($eq);
  ?>
  <a class="export" href="<?= h($export_url) ?>">Tai CSV</a>
</form>

<div class="wrap">
  <table>
    <?php $colspan = 4 + (int)$has_phone + (int)$has_course + (int)$has_event + (int)$has_lesson + (int)$has_pct; ?>
    <thead>
      <tr>
        <th>#</th>
        <th>Thoi gian</th>
        <th>Email</th>
        <th>Ten</th>
        <?php if ($has_phone):  ?><th>SDT</th><?php endif; ?>
        <?php if ($has_course): ?><th>Khoa</th><?php endif; ?>
        <?php if ($has_event):  ?><th>Su kien</th><?php endif; ?>
        <?php if ($has_lesson): ?><th>Bai hoc</th><?php endif; ?>
        <?php if ($has_pct):    ?><th>Tien do</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="<?= $colspan ?>" class="empty">Khong co du lieu</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td style="color:#a0aec0;font-size:12px"><?= (int)$r['id'] ?></td>
        <td style="white-space:nowrap;font-size:12px"><?= h($r['created_at']) ?></td>
        <td><?= h($r['email']) ?></td>
        <td><?= h($r['name'] ?? '') ?></td>
        <?php if ($has_phone):  ?><td style="white-space:nowrap"><?= h($r['phone'] ?? '') ?: '—' ?></td><?php endif; ?>
        <?php if ($has_course): ?><td style="font-weight:600;text-align:center"><?= h($r['course_id'] ?? '') ?></td><?php endif; ?>
        <?php if ($has_event):
          $ev_map = ['session_start'=>'Mở app','lesson_open'=>'Mở bài học','lesson_complete'=>'Hoàn thành bài','quiz_complete'=>'Kết quả quiz'];
          $ev_raw = $r['event'] ?? '';
          $ev_lbl = $ev_map[$ev_raw] ?? $ev_raw;
        ?><td><span class="badge ev-<?= h($ev_raw) ?>" title="<?= h($ev_raw) ?>"><?= h($ev_lbl) ?></span></td><?php endif; ?>
        <?php if ($has_lesson): ?><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['lesson_title'] ?? '') ?>"><?= h($r['lesson_title'] ?? '') ?: '—' ?></td><?php endif; ?>
        <?php if ($has_pct): ?>
        <td>
          <?php if ((int)($r['total_count'] ?? 0) > 0): ?>
          <div class="pct-bar">
            <div class="bar"><div class="fill" style="width:<?= (int)$r['progress_pct'] ?>%"></div></div>
            <span><?= (int)$r['completed_count'] ?>/<?= (int)$r['total_count'] ?> (<?= (int)$r['progress_pct'] ?>%)</span>
          </div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($total_pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= h(qurl('page', $page-1)) ?>">&#8249; Truoc</a><?php endif; ?>
    <?php
    $s = max(1, $page-2); $e = min($total_pages, $page+2);
    for ($i = $s; $i <= $e; $i++):
    ?>
      <?php if ($i === $page): ?><span><?= $i ?></span>
      <?php else: ?><a href="<?= h(qurl('page', $i)) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?><a href="<?= h(qurl('page', $page+1)) ?>">Tiep &#8250;</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="meta"><?= number_format($total_rows) ?> ban ghi<?= $total_pages > 1 ? " &middot; Trang {$page}/{$total_pages}" : '' ?></p>
</div>

</body>
</html>
