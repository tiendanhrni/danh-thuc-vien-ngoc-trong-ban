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

$API_KEY = getenv('ANTHROPIC_API_KEY');
if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured']);
    exit;
}

// Build quiz context: câu hỏi + đáp án đúng = những điểm dạy cốt lõi từ video
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

if ($action === 'summary') {
    $prompt = <<<PROMPT
Bạn là trợ lý học tập của Học Viện RNI — nền tảng phát triển bản thân theo triết lý Return · Nurture · Illuminate của Ruby Nguyen.

Bài học: {$title}
Mô tả: {$desc}
Câu hỏi trắc nghiệm chính (phản ánh nội dung bài):
{$quizLines}
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
- topics: 4 chủ đề sâu sắc, liên quan trực tiếp bài học, đặt câu hỏi kích thích suy nghĩ
- Tiếng Việt thuần, giọng mentor đồng hành, ấm áp
- Chỉ trả JSON, không có markdown hay code block
PROMPT;

    $result = callClaude($API_KEY, $prompt, 800);
    $json = extractJson($result['text'] ?? '');
    echo $json ? json_encode($json, JSON_UNESCAPED_UNICODE) : json_encode(['error' => 'parse_error']);

} else {
    // explain action
    if (!$topic) { echo json_encode(['error' => 'missing_topic']); exit; }

    $prompt = <<<PROMPT
Bạn là trợ lý học tập của Học Viện RNI — nền tảng phát triển bản thân theo triết lý Return · Nurture · Illuminate.

Bài học: {$title}
Mô tả: {$desc}
Câu hỏi trắc nghiệm chính:
{$quizLines}

Học viên muốn hiểu sâu hơn về chủ đề: "{$topic}"

Hãy giải đáp theo cách:
- Sâu sắc, ngắn gọn (120-170 từ)
- Kết nối trực tiếp với nội dung bài và triết lý RNI
- Kết thúc bằng 1 câu hỏi phản chiếu ngắn để học viên tự suy ngẫm
- Giọng ấm áp như mentor đang trò chuyện trực tiếp
- Tiếng Việt thuần, không dùng bullet points hay markdown
PROMPT;

    $result = callClaude($API_KEY, $prompt, 400);
    echo json_encode(['explanation' => $result['text'] ?? ''], JSON_UNESCAPED_UNICODE);
}

function callClaude(string $apiKey, string $prompt, int $maxTokens): array {
    $payload = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => $maxTokens,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

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

    if ($code !== 200) {
        return ['text' => '', 'error' => 'http_' . $code];
    }

    $data = json_decode($res, true);
    return ['text' => $data['content'][0]['text'] ?? ''];
}

function extractJson(string $text): ?array {
    if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if ($decoded !== null) return $decoded;
    }
    return null;
}
