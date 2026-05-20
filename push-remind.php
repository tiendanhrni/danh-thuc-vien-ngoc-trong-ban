<?php
/**
 * push-remind.php — Gửi nhắc lịch học tự động (gọi bằng cron job)
 *
 * Cron job gợi ý (cPanel hoặc server):
 *   # Nhắc buổi trưa 12:00 hàng ngày
 *   0 12 * * * curl -s "https://thuvien.rni.vn/push-remind.php?secret=RNI_PUSH_2026&session=noon"
 *   # Nhắc buổi tối 20:00 hàng ngày
 *   0 20 * * * curl -s "https://thuvien.rni.vn/push-remind.php?secret=RNI_PUSH_2026&session=evening"
 */
header('Content-Type: application/json; charset=utf-8');

$secret  = $_GET['secret'] ?? $_POST['secret'] ?? '';
$session = $_GET['session'] ?? 'noon'; // 'noon' | 'evening'

if ($secret !== 'RNI_PUSH_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Tin nhắn nhắc trưa ────────────────────────────────────────────────────────
$noon_messages = [
    ['title' => '☀️ Giờ học trưa nay', 'body' => 'Dành 10 phút nghỉ trưa để mình cùng vào bài học mới nhé bạn.'],
    ['title' => '🌸 Nghỉ trưa cùng bài học', 'body' => 'Một chút tĩnh lặng cùng bài học trưa nay cho tâm hồn thêm sáng rõ nhé.'],
    ['title' => '🌿 Tạm gác công việc nhé', 'body' => 'Đã đến lúc tạm gác công việc để vào bài học dành riêng cho bạn rồi.'],
    ['title' => '🍃 Thảnh thơi trưa nay', 'body' => 'Mời bạn dành ít phút thảnh thơi trưa nay để tiếp tục bài học cùng tôi.'],
    ['title' => '✨ Bài học đang chờ bạn', 'body' => 'Bài học hôm nay đã sẵn sàng, mình cùng vào học một chút nhé bạn.'],
];

// ── Tin nhắn nhắc tối ────────────────────────────────────────────────────────
$evening_messages = [
    ['title' => '🌙 Trước khi khép lại ngày', 'body' => 'Trước khi khép lại một ngày, mình cùng dành chút thời gian cho bài học nhé.'],
    ['title' => '🕯️ Bài học tối nay', 'body' => '10 phút trọn vẹn cho bài học tối nay để tâm hồn được vỗ về bạn nhé.'],
    ['title' => '🌿 Bài học đang chờ bạn', 'body' => 'Bài học hôm nay vẫn đang chờ bạn ghé thăm, mình vào học một chút nhé.'],
    ['title' => '🌙 Bình yên cuối ngày', 'body' => 'Một chút bình yên với bài học cuối ngày trước khi đi ngủ bạn nhé.'],
    ['title' => '💫 10 phút cho chính mình', 'body' => 'Đừng quên dành 10 phút tối nay để hoàn thành bài học cho chính mình.'],
    ['title' => '🌸 Mài sáng viên ngọc', 'body' => 'Mời bạn vào bài học tối nay để cùng tôi mài sáng viên ngọc bên trong nhé.'],
];

$messages = $session === 'evening' ? $evening_messages : $noon_messages;
$msg = $messages[array_rand($messages)];

// ── VAPID ─────────────────────────────────────────────────────────────────────
define('VAPID_PUBLIC_KEY',  'BH6-XOsyTWZG-ebLHimL-6-0OqhcEs0nTI9FRVoS4fF_zCw9vi48BtsSOr0Nit-X3d4JkR1DJ_ZVjKcwbSMaWLc');
define('VAPID_PRIVATE_KEY', 'ZLDpySCOEG9y7MKztlrv4BgPLP4txxy1l2Rp9ZxRrDg');
define('VAPID_SUBJECT',     'mailto:support@rni.institute');

function b64url_decode($s) { return base64_decode(str_pad(strtr($s,'-_','+/'), strlen($s)+((4-strlen($s)%4)%4), '=')); }
function b64url_encode($s) { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }

function vapid_headers($endpoint) {
    $parts  = parse_url($endpoint);
    $aud    = $parts['scheme'].'://'.$parts['host'];
    $exp    = time() + 43200;
    $header = b64url_encode(json_encode(['typ'=>'JWT','alg'=>'ES256']));
    $claims = b64url_encode(json_encode(['aud'=>$aud,'exp'=>$exp,'sub'=>VAPID_SUBJECT]));
    $signing = $header.'.'.$claims;

    $priv_raw = b64url_decode(VAPID_PRIVATE_KEY);
    $pub_raw  = b64url_decode(VAPID_PUBLIC_KEY);

    $oid   = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $inner = "\x02\x01\x01\x04\x20".$priv_raw."\xa1\x44\x03\x42\x00".$pub_raw;
    $seq1  = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01".$oid;
    $eckey = "\x30".chr(strlen($inner)).$inner;
    $der   = "\x30".chr(strlen($seq1)+4+strlen($eckey))."\x30".chr(strlen($seq1)).$seq1."\x04".chr(strlen($eckey)).$eckey;

    $pkey = openssl_pkey_get_private("-----BEGIN PRIVATE KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PRIVATE KEY-----");
    openssl_sign($signing, $sig, $pkey, OPENSSL_ALGO_SHA256);

    $offset = 4; $rLen = ord($sig[3]);
    if(ord($sig[4])===0){$rLen--;$offset++;}
    $r = substr($sig,$offset,$rLen);
    $offset += $rLen+2; $sLen = ord($sig[$offset-1]);
    if(ord($sig[$offset])===0){$sLen--;$offset++;}
    $s = substr($sig,$offset,$sLen);
    $r = str_pad($r,32,"\x00",STR_PAD_LEFT);
    $s = str_pad($s,32,"\x00",STR_PAD_LEFT);
    $jwt = $signing.'.'.b64url_encode($r.$s);

    return ['Authorization'=>'vapid t='.$jwt.', k='.VAPID_PUBLIC_KEY,'TTL'=>'86400'];
}

// ── DB & gửi ─────────────────────────────────────────────────────────────────
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'rni_courses_quiz_RNI_DTVNBT';
$user = getenv('DB_USER') ?: 'rni_courses_quiz';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) { http_response_code(500); echo json_encode(['error'=>'DB error']); exit; }

$subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC);

$payload = json_encode([
    'title' => $msg['title'],
    'body'  => $msg['body'],
    'url'   => 'https://danh-thuc-vien-ngoc-trong-ban.vercel.app',
    'icon'  => 'https://thuvien.rni.vn/icon-192.png',
]);

$ok = 0; $fail = 0; $expired = [];
foreach ($subs as $sub) {
    $headers = vapid_headers($sub['endpoint']);
    $header_str = "Content-Type: application/octet-stream\r\nContent-Length: ".strlen($payload)."\r\n";
    foreach ($headers as $k => $v) $header_str .= "$k: $v\r\n";

    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>$header_str,'content'=>$payload,'ignore_errors'=>true,'timeout'=>10]]);
    @file_get_contents($sub['endpoint'],false,$ctx);
    $code = 0;
    if(isset($http_response_header[0])){ preg_match('/HTTP\/\S+\s+(\d+)/',$http_response_header[0],$m); $code=(int)($m[1]??0); }

    if($code>=200&&$code<300) $ok++;
    elseif($code===410||$code===404){ $expired[]=$sub['endpoint']; $fail++; }
    else $fail++;
}

foreach ($expired as $ep) $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")->execute([$ep]);

echo json_encode(['session'=>$session,'message_used'=>$msg['title'],'sent'=>$ok,'failed'=>$fail,'expired_removed'=>count($expired)]);
