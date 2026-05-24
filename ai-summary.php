<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$action   = $input['action']   ?? 'summary';
$title    = trim($input['title']    ?? '');
$desc     = trim($input['desc']     ?? '');
$quiz     = $input['quiz']     ?? [];
$practice = trim($input['practice'] ?? '');
$topic    = trim($input['topic']    ?? '');
$videoId  = trim($input['videoId']  ?? '');

$API_KEY = getenv('ANTHROPIC_API_KEY');
if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

// ── Lấy transcript YouTube ──────────────────────────────────────────
$transcript = $videoId ? fetchYouTubeTranscript($videoId) : '';

// ── Build quiz context (fallback khi không có transcript) ───────────
$quizLines = '';
foreach ($quiz as $q) {
    if (!empty($q['q'])) {
        $quizLines .= "Câu hỏi: {$q['q']}\n";
        if (!empty($q['answer'])) {
            $quizLines .= "Đáp án: {$q['answer']}\n";
        }
        $quizLines .= "\n";
    }
}

// ── Build content context ────────────────────────────────────────────
if ($transcript) {
    // Giới hạn ~6000 ký tự (~10-15 phút nói)
    $transcriptTrunc = mb_substr($transcript, 0, 6000, 'UTF-8');
    if (mb_strlen($transcript, 'UTF-8') > 6000) $transcriptTrunc .= '...';
    $contentContext = "Transcript video bài giảng:\n{$transcriptTrunc}";
    $sourceNote = 'transcript';
} else {
    $contentContext = "Câu hỏi & đáp án trắc nghiệm (phản ánh nội dung bài):\n{$quizLines}";
    $sourceNote = 'quiz';
}

// ── Actions ──────────────────────────────────────────────────────────
if ($action === 'summary') {
    $prompt = <<<PROMPT
Bạn là trợ lý học tập của Học Viện RNI — nền tảng phát triển bản thân theo triết lý Return · Nurture · Illuminate của Ruby Nguyen.

Bài học: {$title}
Mô tả: {$desc}

{$contentContext}

Bài thực hành: {$practice}

Trả về JSON theo đúng cấu trúc sau (chỉ JSON, không có text thừa):
{
  "summary": "Tóm tắt 2-3 câu về điểm cốt lõi của bài. Giọng ấm áp, sâu sắc, khích lệ.",
  "topics": [
    {"emoji":"🔑","title":"Tiêu đề thú vị dạng câu hỏi hoặc insight mạnh","hook":"1 câu gây tò mò để học viên muốn khám phá thêm."},
    {"emoji":"💡","title":"...","hook":"..."},
    {"emoji":"🌱","title":"...","hook":"..."},
    {"emoji":"⚡","title":"...","hook":"..."}
  ]
}

Yêu cầu:
- summary: chính xác với nội dung bài, không bịa đặt
- topics: 4 chủ đề sâu sắc từ nội dung thực tế, kích thích suy nghĩ
- Tiếng Việt thuần, giọng mentor đồng hành, ấm áp
- Chỉ trả JSON, không có markdown hay code block
PROMPT;

    $system = 'Bạn là trợ lý AI của Học Viện RNI. Bạn CHỈ được trả lời bằng JSON hợp lệ, không có text hay markdown nào khác. Nếu nội dung bài học còn ít, hãy tạo nội dung phù hợp dựa trên tiêu đề.';
    $result = callClaude($API_KEY, $prompt, 900, $system);
    $rawText = $result['text'] ?? '';
    $json = extractJson($rawText);
    if ($json) {
        $json['source'] = $sourceNote;
        echo json_encode($json, JSON_UNESCAPED_UNICODE);
    } else {
        error_log('[ai-summary] parse_error. HTTP=' . ($result['httpCode'] ?? '?') . ' raw=' . mb_substr($rawText, 0, 500, 'UTF-8'));
        echo json_encode(['error' => 'parse_error', 'raw' => mb_substr($rawText, 0, 200, 'UTF-8')]);
    }

} else {
    // explain action
    if (!$topic) { echo json_encode(['error' => 'missing_topic']); exit; }

    $prompt = <<<PROMPT
Bạn là trợ lý học tập của Học Viện RNI — nền tảng phát triển bản thân theo triết lý Return · Nurture · Illuminate.

Bài học: {$title}
Mô tả: {$desc}

{$contentContext}

Học viên muốn hiểu sâu hơn về chủ đề: "{$topic}"

Hãy giải đáp theo cách:
- Sâu sắc, ngắn gọn (120-170 từ)
- Dẫn dắt trực tiếp từ nội dung bài giảng thực tế
- Kết thúc bằng 1 câu hỏi phản chiếu ngắn để học viên tự suy ngẫm
- Giọng ấm áp như mentor đang trò chuyện trực tiếp
- Tiếng Việt thuần, không dùng bullet points hay markdown
PROMPT;

    $result = callClaude($API_KEY, $prompt, 450);
    echo json_encode(['explanation' => $result['text'] ?? '', 'source' => $sourceNote], JSON_UNESCAPED_UNICODE);
}

// ════════════════════════════════════════════════════════════════════
// YouTube transcript fetching
// ════════════════════════════════════════════════════════════════════

function fetchYouTubeTranscript(string $videoId): string {
    // Phương pháp 1: timedtext API trực tiếp (nhanh, hoạt động cho unlisted)
    $transcript = tryTimedtextDirect($videoId);
    if ($transcript) return $transcript;

    // Phương pháp 2: scrape trang watch để lấy URL caption chính xác
    $transcript = tryTimedtextViaScrape($videoId);
    return $transcript;
}

function tryTimedtextDirect(string $videoId): string {
    $variants = [
        "https://www.youtube.com/api/timedtext?v={$videoId}&lang=vi&fmt=json3",
        "https://www.youtube.com/api/timedtext?v={$videoId}&lang=vi&fmt=json3&kind=asr",
        "https://www.youtube.com/api/timedtext?v={$videoId}&lang=vi-VN&fmt=json3",
    ];
    foreach ($variants as $url) {
        $response = httpGet($url);
        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['events'])) {
                return parseTimedtextEvents($data['events']);
            }
        }
    }
    return '';
}

function tryTimedtextViaScrape(string $videoId): string {
    $html = httpGet(
        "https://www.youtube.com/watch?v={$videoId}",
        [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: vi-VN,vi;q=0.9,en;q=0.8',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]
    );
    if (!$html) return '';

    // Tìm captionTracks trong ytInitialPlayerResponse
    if (!preg_match('/"captionTracks":\[(.*?)\](?=[,}])/s', $html, $m)) return '';

    $tracks = json_decode('[' . $m[1] . ']', true) ?: [];

    // Ưu tiên track tiếng Việt
    $captionUrl = '';
    foreach ($tracks as $track) {
        $lang = $track['languageCode'] ?? '';
        if (in_array($lang, ['vi', 'vi-VN'])) {
            $captionUrl = html_entity_decode($track['baseUrl'] ?? '');
            break;
        }
    }
    // Fallback: track đầu tiên
    if (!$captionUrl && !empty($tracks[0]['baseUrl'])) {
        $captionUrl = html_entity_decode($tracks[0]['baseUrl']);
    }
    if (!$captionUrl) return '';

    $response = httpGet($captionUrl . '&fmt=json3');
    if (!$response) return '';

    $data = json_decode($response, true);
    return !empty($data['events']) ? parseTimedtextEvents($data['events']) : '';
}

function parseTimedtextEvents(array $events): string {
    $parts = [];
    foreach ($events as $event) {
        if (empty($event['segs'])) continue;
        $line = '';
        foreach ($event['segs'] as $seg) {
            $line .= $seg['utf8'] ?? '';
        }
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line && $line !== '') $parts[] = $line;
    }
    return implode(' ', $parts);
}

function httpGet(string $url, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $result) ? $result : '';
}

// ════════════════════════════════════════════════════════════════════
// Claude API
// ════════════════════════════════════════════════════════════════════

function callClaude(string $apiKey, string $prompt, int $maxTokens, string $system = ''): array {
    $body = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($system) $body['system'] = $system;
    $payload = json_encode($body);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return ['text' => '', 'error' => 'http_' . $code, 'httpCode' => $code];

    $data = json_decode($res, true);
    return ['text' => $data['content'][0]['text'] ?? '', 'httpCode' => $code];
}

function extractJson(string $text): ?array {
    // Strip markdown fences if present
    $text = preg_replace('/^```(?:json)?\s*/im', '', $text);
    $text = preg_replace('/```\s*$/im', '', $text);
    $text = trim($text);

    // Try to find the outermost JSON object
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        if ($decoded !== null) return $decoded;
    }
    return null;
}
