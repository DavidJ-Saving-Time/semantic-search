<?php
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON payload.'
    ]);
    exit;
}

$docId = $input['doc_id'] ?? null;
if (!is_numeric($docId)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'A valid doc_id is required.'
    ]);
    exit;
}
$docId = (int)$docId;

$openrouterKey = getenv('OPENROUTER_API_KEY') ?: '';
if ($openrouterKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'OpenRouter API key is not configured.'
    ]);
    exit;
}

$promptPath = __DIR__ . '/prompt_claud.md';
if (!is_file($promptPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Prompt template is missing.'
    ]);
    exit;
}

$systemPrompt = trim(file_get_contents($promptPath));
if ($systemPrompt === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Prompt template is empty.'
    ]);
    exit;
}

$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';

$connStr = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s',
    $PGHOST,
    $PGPORT,
    $PGDATABASE,
    $PGUSER,
    $PGPASSWORD
);

$pgconn = @pg_connect($connStr);
if ($pgconn === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database connection failed.'
    ]);
    exit;
}

$sql = <<<SQL
SELECT
  md,
  meta->>'title' AS title,
  meta->>'issue' AS issue,
  date::text AS issue_date
FROM docs
WHERE id = $1
LIMIT 1;
SQL;

$res = pg_query_params($pgconn, $sql, [$docId]);
if ($res === false) {
    pg_close($pgconn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load document.'
    ]);
    exit;
}

$docRow = pg_fetch_assoc($res) ?: null;

if (!$docRow) {
    pg_close($pgconn);
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Document not found.'
    ]);
    exit;
}

$articleMarkdown = $docRow['md'] ?? '';
if (trim($articleMarkdown) === '') {
    pg_close($pgconn);
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Document is missing markdown content.'
    ]);
    exit;
}

$title = $docRow['title'] ?? '';

$issueNameRaw = trim((string)($docRow['issue'] ?? ''));
$issueNameForPrompt = $issueNameRaw !== '' ? $issueNameRaw : 'Illustrated London News';

$issueDateRaw = trim((string)($docRow['issue_date'] ?? ''));
$issueDateForPrompt = $issueDateRaw !== '' ? $issueDateRaw : '1850-01-05';

$systemPrompt = strtr($systemPrompt, [
    '{{NEWSPAPER}}' => $issueNameForPrompt,
    '{{ISSUE_DATE}}' => $issueDateForPrompt,
]);

$location = 'London, England';

$userParts = [];
$userParts[] = 'Provide historical context following the format guidelines.';
if ($title !== '') {
    $userParts[] = 'Article Title: ' . $title;
}
$userParts[] = 'Document ID: ' . $docId;
if ($issueNameForPrompt !== '') {
    $userParts[] = 'Newspaper: ' . $issueNameForPrompt;
}
if ($issueDateForPrompt !== '') {
    $userParts[] = 'Issue Date: ' . $issueDateForPrompt;
}
$userParts[] = 'Location: ' . $location;
$userParts[] = "Article Markdown:\n" . $articleMarkdown;
$userMessage = implode("\n\n", $userParts);

$payload = json_encode([
    'model' => 'anthropic/claude-sonnet-4',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userMessage],
    ],
    'max_output_tokens' => 900,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $openrouterKey,
];

$referer = $_SERVER['HTTP_HOST'] ?? '';
if ($referer !== '') {
    $scheme = 'https';
    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = $_SERVER['REQUEST_SCHEME'];
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '80') {
        $scheme = 'http';
    }
    $headers[] = 'HTTP-Referer: ' . $scheme . '://' . $referer;
}

$headers[] = 'X-Title: Illustrated London News Context';

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    pg_close($pgconn);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'OpenRouter request failed: ' . ($error ?: 'unknown error')
    ]);
    exit;
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    pg_close($pgconn);
    http_response_code($httpStatus);
    $detail = $response;
    $decodedError = json_decode($response, true);
    if (is_array($decodedError) && isset($decodedError['error'])) {
        $detail = is_string($decodedError['error']) ? $decodedError['error'] : json_encode($decodedError['error']);
    }
    echo json_encode([
        'ok' => false,
        'error' => 'OpenRouter responded with HTTP ' . $httpStatus . ': ' . $detail
    ]);
    exit;
}

$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    pg_close($pgconn);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid OpenRouter response.'
    ]);
    exit;
}

$content = '';
if (isset($decoded['choices'][0]['message']['content'])) {
    $msgContent = $decoded['choices'][0]['message']['content'];
    if (is_string($msgContent)) {
        $content = $msgContent;
    } elseif (is_array($msgContent)) {
        $parts = [];
        foreach ($msgContent as $segment) {
            if (is_string($segment)) {
                $parts[] = $segment;
            } elseif (is_array($segment) && isset($segment['text']) && is_string($segment['text'])) {
                $parts[] = $segment['text'];
            }
        }
        $content = implode('', $parts);
    }
}

if ($content === '') {
    pg_close($pgconn);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'Claude returned an empty response.'
    ]);
    exit;
}

$insertSql = 'INSERT INTO hcontext (fid, context) VALUES ($1, $2)';
$insertRes = pg_query_params($pgconn, $insertSql, [$docId, $content]);
if ($insertRes === false) {
    pg_close($pgconn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to store historical context.'
    ]);
    exit;
}

pg_close($pgconn);

echo json_encode([
    'ok' => true,
    'content' => $content,
]);
