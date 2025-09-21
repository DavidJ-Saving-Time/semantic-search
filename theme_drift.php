<?php
ini_set('display_errors', '0');
setlocale(LC_NUMERIC, 'C');

$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pg_pdo(string $host, string $port, string $db, string $user, string $pass): PDO
{
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--application_name=theme_drift_ui'";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function respond_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function parse_vector_text(?string $text): array
{
    if ($text === null) {
        return [];
    }
    $text = trim($text);
    if ($text === '' || $text === '[]') {
        return [];
    }
    $text = trim($text, '[]');
    if ($text === '') {
        return [];
    }
    $parts = explode(',', $text);
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            $out[] = 0.0;
            continue;
        }
        $out[] = (float)$part;
    }
    return $out;
}

function tokenize_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $lower = mb_strtolower($text, 'UTF-8');
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return [];
    }
    return $parts;
}

function extract_key_phrases(array $texts, int $limit = 3): array
{
    $stopWords = [
        'the' => true, 'and' => true, 'for' => true, 'that' => true, 'with' => true,
        'from' => true, 'this' => true, 'were' => true, 'have' => true, 'which' => true,
        'into' => true, 'about' => true, 'their' => true, 'when' => true, 'where' => true,
        'what' => true, 'will' => true, 'shall' => true, 'upon' => true, 'been' => true,
        'over' => true, 'after' => true, 'before' => true, 'between' => true, 'there' => true,
        'such' => true, 'they' => true, 'his' => true, 'her' => true, 'its' => true,
        'into' => true, 'than' => true, 'other' => true, 'through' => true, 'also' => true,
        'many' => true, 'more' => true, 'most' => true, 'some' => true, 'very' => true,
        'much' => true, 'every' => true, 'each' => true, 'none' => true, 'only' => true,
        'been' => true, 'our' => true, 'your' => true, 'their' => true, 'those' => true,
        'these' => true, 'than' => true, 'into' => true
    ];

    $phraseScores = [];
    $fallbackTokens = [];

    foreach ($texts as $text) {
        if (!is_string($text) || trim($text) === '') {
            continue;
        }
        $tokens = tokenize_text($text);
        if (empty($tokens)) {
            continue;
        }
        $fallbackTokens = array_merge($fallbackTokens, $tokens);
        $count = count($tokens);
        for ($n = 3; $n >= 2; $n--) {
            if ($count < $n) {
                continue;
            }
            for ($i = 0; $i <= $count - $n; $i++) {
                $slice = array_slice($tokens, $i, $n);
                $allStop = true;
                $hasContent = false;
                foreach ($slice as $token) {
                    $length = mb_strlen($token, 'UTF-8');
                    $isStop = isset($stopWords[$token]);
                    if (!$isStop) {
                        $allStop = false;
                    }
                    if ($length >= 4 && !$isStop) {
                        $hasContent = true;
                    }
                }
                if ($allStop || !$hasContent) {
                    continue;
                }
                if (isset($stopWords[$slice[0]]) || isset($stopWords[$slice[$n - 1]])) {
                    continue;
                }
                $phrase = implode(' ', $slice);
                $score = ($phraseScores[$phrase] ?? 0) + 1 + ($n * 0.05);
                $phraseScores[$phrase] = $score;
            }
        }
    }

    arsort($phraseScores, SORT_NUMERIC);
    $phrases = [];
    foreach ($phraseScores as $phrase => $score) {
        $pretty = mb_convert_case($phrase, MB_CASE_TITLE, 'UTF-8');
        if (!in_array($pretty, $phrases, true)) {
            $phrases[] = $pretty;
        }
        if (count($phrases) >= $limit) {
            break;
        }
    }

    if (count($phrases) >= $limit) {
        return array_slice($phrases, 0, $limit);
    }

    $tokenScores = [];
    foreach ($fallbackTokens as $token) {
        if (mb_strlen($token, 'UTF-8') < 4) {
            continue;
        }
        if (isset($stopWords[$token])) {
            continue;
        }
        $tokenScores[$token] = ($tokenScores[$token] ?? 0) + 1;
    }
    arsort($tokenScores, SORT_NUMERIC);
    foreach ($tokenScores as $token => $score) {
        $pretty = mb_convert_case($token, MB_CASE_TITLE, 'UTF-8');
        if (!in_array($pretty, $phrases, true)) {
            $phrases[] = $pretty;
        }
        if (count($phrases) >= $limit) {
            break;
        }
    }

    return array_slice($phrases, 0, $limit);
}

function select_representative_headlines(array $docs, int $limit = 2): array
{
    $headlines = [];
    foreach ($docs as $doc) {
        $title = trim((string)($doc['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        if (!in_array($title, $headlines, true)) {
            $headlines[] = $title;
        }
        if (count($headlines) >= $limit) {
            return $headlines;
        }
    }

    foreach ($docs as $doc) {
        $summary = trim((string)($doc['summary'] ?? ''));
        if ($summary === '') {
            continue;
        }
        $snippet = mb_substr($summary, 0, 120, 'UTF-8');
        if (mb_strlen($summary, 'UTF-8') > 120) {
            $snippet .= '…';
        }
        if (!in_array($snippet, $headlines, true)) {
            $headlines[] = $snippet;
        }
        if (count($headlines) >= $limit) {
            break;
        }
    }

    return array_slice($headlines, 0, $limit);
}

function format_period_label(string $period, ?string $startDate): string
{
    if ($startDate === null || $startDate === '') {
        return '';
    }
    $timestamp = strtotime($startDate);
    if ($timestamp === false) {
        return $startDate;
    }
    if ($period === 'month') {
        return date('F Y', $timestamp);
    }
    return date('Y', $timestamp);
}

function fetch_period_row(PDO $pdo, string $period, string $periodKey, bool $isCombined, ?string $pubname): ?array
{
    $sql = "SELECT id, period, period_key, period_start::text AS period_start, period_end::text AS period_end, pubname, is_combined FROM public.period_embeddings WHERE period = :period AND period_key = :period_key AND is_combined = :is_combined";
    if (!$isCombined) {
        $sql .= " AND pubname = :pubname";
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':period', $period, PDO::PARAM_STR);
    $stmt->bindValue(':period_key', $periodKey, PDO::PARAM_STR);
    $stmt->bindValue(':is_combined', $isCombined, PDO::PARAM_BOOL);
    if (!$isCombined) {
        $stmt->bindValue(':pubname', $pubname, PDO::PARAM_STR);
    }
    $stmt->execute();

    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    $row['id'] = isset($row['id']) ? (int)$row['id'] : null;

    return $row;
}

$action = $_GET['action'] ?? '';

if ($action === 'data') {
    try {
        $period = $_GET['period'] ?? 'year';
        $period = $period === 'month' ? 'month' : 'year';

        $aggregation = $_GET['aggregation'] ?? 'combined';
        $isCombined = $aggregation !== 'per_pub';

        $pubname = '';
        if (!$isCombined) {
            $pubname = trim((string)($_GET['pubname'] ?? ''));
            if ($pubname === '') {
                respond_json(400, ['ok' => false, 'error' => 'Select a publication for per-publication view.']);
            }
        }

        $startYearRaw = $_GET['start_year'] ?? '';
        $endYearRaw = $_GET['end_year'] ?? '';

        $startYear = is_numeric($startYearRaw) ? (int)$startYearRaw : null;
        $endYear = is_numeric($endYearRaw) ? (int)$endYearRaw : null;

        if ($startYear !== null && ($startYear < 1500 || $startYear > 2100)) {
            $startYear = null;
        }
        if ($endYear !== null && ($endYear < 1500 || $endYear > 2100)) {
            $endYear = null;
        }
        if ($startYear !== null && $endYear !== null && $startYear > $endYear) {
            [$startYear, $endYear] = [$endYear, $startYear];
        }

        $limitRaw = $_GET['limit'] ?? '';
        $limit = is_numeric($limitRaw) ? (int)$limitRaw : 360;
        if ($limit < 10) {
            $limit = 10;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }

        $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD);

        $where = ['period = :period'];
        if ($isCombined) {
            $where[] = 'is_combined = true';
        } else {
            $where[] = 'is_combined = false';
            $where[] = 'pubname = :pubname';
        }
        if ($startYear !== null) {
            $where[] = 'period_start >= :start_date';
        }
        if ($endYear !== null) {
            $where[] = 'period_start <= :end_date';
        }

        $sql = "SELECT id, period, period_key, period_start::text AS period_start, period_end::text AS period_end, pubname, is_combined, model, dim, article_count, token_count, embedding::text AS embedding_text FROM public.period_embeddings WHERE " . implode(' AND ', $where) . " ORDER BY period_start ASC LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':period', $period, PDO::PARAM_STR);
        if (!$isCombined) {
            $stmt->bindValue(':pubname', $pubname, PDO::PARAM_STR);
        }
        if ($startYear !== null) {
            $startDate = sprintf('%04d-01-01', $startYear);
            $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        }
        if ($endYear !== null) {
            $endDate = sprintf('%04d-12-31', $endYear);
            $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll();

        $items = [];
        $minYear = null;
        $maxYear = null;
        foreach ($rows as $row) {
            $embedding = parse_vector_text($row['embedding_text'] ?? null);
            if (empty($embedding)) {
                continue;
            }
            $dim = isset($row['dim']) ? (int)$row['dim'] : null;
            if ($dim !== null && $dim > 0 && count($embedding) !== $dim) {
                $embedding = array_slice($embedding, 0, $dim);
            }

            $startStr = $row['period_start'] ?? null;
            $label = '';
            $yearVal = null;
            if ($startStr !== null) {
                $timestamp = strtotime($startStr);
                if ($timestamp !== false) {
                    if ($period === 'month') {
                        $label = date('F Y', $timestamp);
                    } else {
                        $label = date('Y', $timestamp);
                    }
                }
                $yearVal = (int)substr($startStr, 0, 4);
                if ($minYear === null || $yearVal < $minYear) {
                    $minYear = $yearVal;
                }
                if ($maxYear === null || $yearVal > $maxYear) {
                    $maxYear = $yearVal;
                }
            }

            $items[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'period_key' => $row['period_key'] ?? null,
                'period_start' => $row['period_start'] ?? null,
                'period_end' => $row['period_end'] ?? null,
                'label' => $label,
                'pubname' => $row['pubname'] ?? null,
                'is_combined' => (bool)$row['is_combined'],
                'model' => $row['model'] ?? null,
                'dim' => $dim,
                'article_count' => isset($row['article_count']) ? (int)$row['article_count'] : null,
                'token_count' => isset($row['token_count']) ? (int)$row['token_count'] : null,
                'year' => $yearVal,
                'embedding' => $embedding,
            ];
        }

        if (empty($items)) {
            respond_json(200, [
                'ok' => true,
                'period' => $period,
                'count' => 0,
                'items' => [],
                'min_year' => $minYear,
                'max_year' => $maxYear,
            ]);
        }

        respond_json(200, [
            'ok' => true,
            'period' => $period,
            'aggregation' => $isCombined ? 'combined' : 'per_pub',
            'count' => count($items),
            'min_year' => $minYear,
            'max_year' => $maxYear,
            'items' => $items,
        ]);
    } catch (Throwable $e) {
        respond_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'nearest_docs' || $action === 'topic_labels' || $action === 'drift' || $action === 'insight') {
    try {
        $period = $_GET['period'] ?? 'year';
        $period = $period === 'month' ? 'month' : 'year';

        $aggregation = $_GET['aggregation'] ?? 'combined';
        $isCombined = $aggregation !== 'per_pub';

        $pubname = null;
        if (!$isCombined) {
            $pubname = trim((string)($_GET['pubname'] ?? ''));
            if ($pubname === '') {
                respond_json(400, ['ok' => false, 'error' => 'Select a publication to continue.']);
            }
        }

        $periodKey = trim((string)($_GET['period_key'] ?? ''));
        if ($periodKey === '') {
            respond_json(400, ['ok' => false, 'error' => 'Missing period key.']);
        }

        $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD);
        $periodRow = fetch_period_row($pdo, $period, $periodKey, $isCombined, $pubname);
        if (!$periodRow || !isset($periodRow['id'])) {
            respond_json(404, ['ok' => false, 'error' => 'Selected period not found.']);
        }

        $periodId = (int)$periodRow['id'];
        $periodStart = $periodRow['period_start'] ?? null;
        $periodEnd = $periodRow['period_end'] ?? null;

        if ($action === 'nearest_docs') {
            $limitRaw = $_GET['limit'] ?? '';
            $limit = is_numeric($limitRaw) ? (int)$limitRaw : 10;
            if ($limit < 1) {
                $limit = 1;
            }
            if ($limit > 25) {
                $limit = 25;
            }

            $sql = "SELECT d.id, d.pubname, d.date::text AS date, COALESCE(NULLIF(d.summary_clean, ''), NULLIF(d.summary_raw, ''), '') AS summary, 1 - (d.embedding_hv <=> p.embedding_hv) AS similarity FROM public.period_embeddings p JOIN public.docs d ON d.embedding_hv IS NOT NULL AND d.date IS NOT NULL AND d.date >= p.period_start AND d.date <= p.period_end AND (p.is_combined = true OR d.pubname = p.pubname) WHERE p.id = :id ORDER BY d.embedding_hv <=> p.embedding_hv ASC LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $periodId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $docs = [];
            foreach ($rows as $row) {
                $summary = (string)($row['summary'] ?? '');
                $summary = trim($summary);
                if (mb_strlen($summary) > 300) {
                    $summary = mb_substr($summary, 0, 300) . '…';
                }
                $docs[] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : null,
                    'pubname' => $row['pubname'] ?? null,
                    'date' => $row['date'] ?? null,
                    'summary' => $summary,
                    'similarity' => isset($row['similarity']) ? (float)$row['similarity'] : null,
                ];
            }

            respond_json(200, [
                'ok' => true,
                'count' => count($docs),
                'period' => [
                    'period_key' => $periodKey,
                    'start' => $periodStart,
                    'end' => $periodEnd,
                ],
                'docs' => $docs,
            ]);
        }

        if ($action === 'insight') {
            $limit = 15;
            $sql = "SELECT d.id, d.pubname, d.date::text AS date, COALESCE(NULLIF(d.summary_clean, ''), NULLIF(d.summary_raw, ''), '') AS summary, d.meta->>'title' AS title, 1 - (d.embedding_hv <=> p.embedding_hv) AS similarity FROM public.period_embeddings p JOIN public.docs d ON d.embedding_hv IS NOT NULL AND d.date IS NOT NULL AND d.date >= p.period_start AND d.date <= p.period_end AND (p.is_combined = true OR d.pubname = p.pubname) WHERE p.id = :id ORDER BY d.embedding_hv <=> p.embedding_hv ASC LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $periodId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $summaries = [];
            foreach ($rows as $row) {
                $summary = trim((string)($row['summary'] ?? ''));
                if ($summary !== '') {
                    $summaries[] = $summary;
                }
            }

            $phrases = extract_key_phrases($summaries, 3);
            $headlines = select_representative_headlines($rows, 2);

            $monthIso = null;
            if (is_string($periodStart) && strlen($periodStart) >= 7) {
                $monthIso = substr($periodStart, 0, 7);
            }

            respond_json(200, [
                'ok' => true,
                'period' => [
                    'period_key' => $periodKey,
                    'start' => $periodStart,
                    'end' => $periodEnd,
                    'label' => format_period_label($period, $periodStart),
                ],
                'summary' => [
                    'month_iso' => $monthIso,
                    'phrases' => $phrases,
                    'headlines' => $headlines,
                    'doc_count' => count($rows),
                ],
            ]);
        }

        if ($action === 'topic_labels') {
            $limitRaw = $_GET['limit'] ?? '';
            $limit = is_numeric($limitRaw) ? (int)$limitRaw : 5;
            if ($limit < 1) {
                $limit = 1;
            }
            if ($limit > 15) {
                $limit = 15;
            }

            $sql = "SELECT t.topic, 1 - (t.emb_hv <=> p.embedding_hv) AS similarity FROM public.period_embeddings p JOIN public.topic_labels t ON t.emb_hv IS NOT NULL WHERE p.id = :id ORDER BY t.emb_hv <=> p.embedding_hv ASC LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $periodId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $topics = [];
            foreach ($rows as $row) {
                $topics[] = [
                    'topic' => $row['topic'] ?? null,
                    'similarity' => isset($row['similarity']) ? (float)$row['similarity'] : null,
                ];
            }

            respond_json(200, [
                'ok' => true,
                'count' => count($topics),
                'period' => [
                    'period_key' => $periodKey,
                    'start' => $periodStart,
                    'end' => $periodEnd,
                ],
                'topics' => $topics,
            ]);
        }

        if ($action === 'drift') {
            $sql = "SELECT prev.period_key AS prev_period_key, prev.period_start::text AS prev_start, prev.period_end::text AS prev_end, CASE WHEN prev.embedding_hv IS NOT NULL THEN (prev.embedding_hv <=> curr.embedding_hv) ELSE NULL END AS distance_to_prev, nxt.period_key AS next_period_key, nxt.period_start::text AS next_start, nxt.period_end::text AS next_end, CASE WHEN nxt.embedding_hv IS NOT NULL THEN (nxt.embedding_hv <=> curr.embedding_hv) ELSE NULL END AS distance_to_next FROM public.period_embeddings curr LEFT JOIN LATERAL ( SELECT period_key, period_start, period_end, embedding_hv FROM public.period_embeddings WHERE period = :period AND period_start < curr.period_start AND is_combined = curr.is_combined AND (:is_combined OR pubname = curr.pubname) ORDER BY period_start DESC LIMIT 1 ) AS prev ON true LEFT JOIN LATERAL ( SELECT period_key, period_start, period_end, embedding_hv FROM public.period_embeddings WHERE period = :period AND period_start > curr.period_start AND is_combined = curr.is_combined AND (:is_combined OR pubname = curr.pubname) ORDER BY period_start ASC LIMIT 1 ) AS nxt ON true WHERE curr.id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':period', $period, PDO::PARAM_STR);
            $stmt->bindValue(':is_combined', $isCombined, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $periodId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();

            $prev = null;
            if ($row && $row['prev_period_key'] !== null) {
                $prev = [
                    'period_key' => $row['prev_period_key'],
                    'start' => $row['prev_start'],
                    'end' => $row['prev_end'],
                    'distance' => $row['distance_to_prev'] !== null ? (float)$row['distance_to_prev'] : null,
                ];
            }

            $next = null;
            if ($row && $row['next_period_key'] !== null) {
                $next = [
                    'period_key' => $row['next_period_key'],
                    'start' => $row['next_start'],
                    'end' => $row['next_end'],
                    'distance' => $row['distance_to_next'] !== null ? (float)$row['distance_to_next'] : null,
                ];
            }

            respond_json(200, [
                'ok' => true,
                'period' => [
                    'period_key' => $periodKey,
                    'start' => $periodStart,
                    'end' => $periodEnd,
                ],
                'prev' => $prev,
                'next' => $next,
            ]);
        }

        respond_json(400, ['ok' => false, 'error' => 'Unsupported helper action.']);
    } catch (Throwable $e) {
        respond_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

$pdo = null;
$pubnames = [];
$rangeInfo = null;
$errorMessage = null;

try {
    $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD);
    $pubStmt = $pdo->query("SELECT DISTINCT pubname FROM public.period_embeddings WHERE pubname IS NOT NULL AND pubname <> '' ORDER BY pubname");
    $pubnames = $pubStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $rangeStmt = $pdo->query("SELECT MIN(period_start) AS min_start, MAX(period_end) AS max_end FROM public.period_embeddings");
    $rangeInfo = $rangeStmt->fetch() ?: null;
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$defaultStartYear = 1850;
$defaultEndYear = 1900;
if ($rangeInfo) {
    $minYear = isset($rangeInfo['min_start']) && $rangeInfo['min_start'] !== null ? (int)substr($rangeInfo['min_start'], 0, 4) : null;
    $maxYear = isset($rangeInfo['max_end']) && $rangeInfo['max_end'] !== null ? (int)substr($rangeInfo['max_end'], 0, 4) : null;
    if ($minYear !== null) {
        $defaultStartYear = $minYear;
    }
    if ($maxYear !== null) {
        $defaultEndYear = $maxYear;
    }
}
if ($defaultStartYear > $defaultEndYear) {
    $defaultStartYear = $defaultEndYear;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Theme Drift Explorer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body {
            background-color: #f8f9fa;
        }
        #chart {
            min-height: 560px;
        }
        #chartWrapper {
            position: relative;
        }
        .form-section {
            background-color: #ffffff;
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
        }
        .sticky-controls {
            position: sticky;
            top: 1rem;
            z-index: 100;
        }
        .hover-card {
            position: absolute;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.15);
            max-width: 320px;
            padding: 0.75rem 1rem;
            transform: translate(-50%, -100%);
            transition: opacity 0.12s ease;
            opacity: 0;
        }
        .hover-card.visible {
            opacity: 1;
        }
        .hover-card h4 {
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }
        .hover-card .muted {
            color: #6c757d;
            font-size: 0.8rem;
        }
        .phrase-badge {
            display: inline-block;
            background-color: #e9ecef;
            color: #495057;
            border-radius: 999px;
            padding: 0.1rem 0.6rem;
            font-size: 0.75rem;
            margin: 0.1rem 0.2rem 0.1rem 0;
            white-space: nowrap;
        }
        .headline-item {
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
        }
        .headline-item::before {
            content: '•';
            margin-right: 0.3rem;
            color: #adb5bd;
        }
        .cluster-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        .cluster-legend .legend-swatch {
            width: 0.9rem;
            height: 0.9rem;
            border-radius: 0.2rem;
            margin-right: 0.35rem;
            display: inline-block;
            vertical-align: middle;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .histogram {
            display: flex;
            align-items: flex-end;
            gap: 0.35rem;
            min-height: 90px;
            margin-top: 0.5rem;
        }
        .histogram-bar {
            background: linear-gradient(180deg, rgba(13,110,253,0.75), rgba(13,110,253,0.35));
            width: 18px;
            border-radius: 0.3rem 0.3rem 0 0;
            position: relative;
        }
        .histogram-bar span {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translate(-50%, -0.2rem);
            font-size: 0.65rem;
            color: #6c757d;
        }
        .histogram-bar em {
            position: absolute;
            top: 0.15rem;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.65rem;
            color: #fff;
            font-style: normal;
        }
        #hoverCard {
            display: none;
        }
        #hoverCard.visible {
            display: block;
        }
        .year-brush {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .year-brush .range-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .year-brush input[type="range"] {
            flex: 1 1 auto;
        }
        .year-brush .badge {
            font-size: 0.7rem;
        }
        .cluster-summary-empty {
            color: #6c757d;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="form-section sticky-controls">
                <h1 class="h4 mb-3">Theme Drift Over Time</h1>
                <p class="text-muted small">Project historical clusters by reducing the <code>period_embeddings</code> vectors into two dimensions. Adjust the filters to watch cultural themes shift across decades.</p>
                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger" role="alert">
                        Unable to load metadata: <?= h($errorMessage) ?>
                    </div>
                <?php endif; ?>
                <form id="controlForm" class="row g-3">
                    <div class="col-12">
                        <label for="period" class="form-label">Granularity</label>
                        <select class="form-select" id="period" name="period">
                            <option value="year">Yearly embeddings</option>
                            <option value="month">Monthly embeddings</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="aggregation" class="form-label">Aggregation</label>
                        <select class="form-select" id="aggregation" name="aggregation">
                            <option value="combined" selected>Combined across publications</option>
                            <option value="per_pub">Single publication</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="pubname" class="form-label">Publication</label>
                        <select class="form-select" id="pubname" name="pubname" disabled>
                            <option value="">Choose a publication</option>
                            <?php foreach ($pubnames as $pub): ?>
                                <option value="<?= h($pub) ?>"><?= h($pub) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label for="startYear" class="form-label">Start year</label>
                        <input type="number" class="form-control" id="startYear" name="start_year" value="<?= h((string)$defaultStartYear) ?>" min="1500" max="2100">
                    </div>
                    <div class="col-6">
                        <label for="endYear" class="form-label">End year</label>
                        <input type="number" class="form-control" id="endYear" name="end_year" value="<?= h((string)$defaultEndYear) ?>" min="1500" max="2100">
                    </div>
                    <div class="col-6">
                        <label for="limit" class="form-label">Limit periods</label>
                        <input type="number" class="form-control" id="limit" name="limit" value="360" min="10" max="1000" step="10">
                    </div>
                    <div class="col-6">
                        <label for="neighbors" class="form-label">UMAP neighbors</label>
                        <input type="number" class="form-control" id="neighbors" name="neighbors" value="15" min="5" max="60">
                    </div>
                    <div class="col-12">
                        <label for="minDist" class="form-label">UMAP min distance</label>
                        <input type="number" class="form-control" id="minDist" name="min_dist" value="0.15" step="0.01" min="0.01" max="0.99">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">Update view</button>
                        <button type="button" class="btn btn-outline-secondary" id="resetButton">Reset</button>
                    </div>
                </form>
                <div class="mt-3 small text-muted">
                    Tip: experiment with monthly granularity to magnify local events, or tighten the year range to focus on specific eras.
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h5">Temporal map</h2>
                    <p class="text-muted small mb-3">Project the monthly and yearly embeddings into two dimensions, then colour the dots to trace either chronological drift or thematic clusters. Hover to preview representative coverage; lasso a blob to summarise its contents.</p>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <div class="btn-group" role="group" aria-label="Colour mode">
                            <input type="radio" class="btn-check" name="colorMode" id="colorModeYear" autocomplete="off" value="year" checked>
                            <label class="btn btn-outline-primary btn-sm" for="colorModeYear">Colour by year</label>
                            <input type="radio" class="btn-check" name="colorMode" id="colorModeCluster" autocomplete="off" value="cluster">
                            <label class="btn btn-outline-primary btn-sm" for="colorModeCluster">Colour by cluster</label>
                        </div>
                        <div class="btn-group" role="group" aria-label="Interaction mode">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="panModeButton" title="Pan & zoom the map">Pan</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="lassoModeButton" title="Lasso points to summarise a blob">Lasso</button>
                        </div>
                        <div class="ms-auto small text-muted" id="clusterSummaryHint">Use lasso selection to inspect a theme.</div>
                    </div>
                    <div id="clusterLegend" class="cluster-legend mb-3 d-none" aria-live="polite"></div>
                    <div id="chartWrapper">
                        <div id="chart" class="w-100"></div>
                        <div id="hoverCard" class="hover-card" role="dialog" aria-live="polite">
                            <div class="muted">Hover over a dot to preview that period.</div>
                        </div>
                    </div>
                    <div id="status" class="mt-2 text-muted small">Adjust the filters to load embeddings.</div>
                    <div id="yearBrush" class="year-brush mt-3 d-none" aria-live="polite">
                        <div class="d-flex align-items-center justify-content-between">
                            <strong class="small">Year brush</strong>
                            <span class="badge text-bg-light" id="yearBrushLabel">Showing all years</span>
                        </div>
                        <div class="range-inputs">
                            <input type="range" id="yearBrushStart" min="0" max="0" value="0" step="1">
                            <input type="range" id="yearBrushEnd" min="0" max="0" value="0" step="1">
                        </div>
                        <div class="small text-muted">Drag the sliders to spotlight one or more years; other dots fade into the background.</div>
                    </div>
                </div>
            </div>
            <div id="summary" class="mb-3"></div>
            <div id="clusterSummary" class="card shadow-sm mb-3 d-none">
                <div class="card-body">
                    <h3 class="h6 mb-2">Cluster summary</h3>
                    <div id="clusterSummaryContent" class="cluster-summary-empty">Lasso a cluster to see which years and phrases dominate.</div>
                </div>
            </div>
            <div id="detail" class="card shadow-sm">
                <div class="card-body">
                    <h3 class="h6">Period details</h3>
                    <div id="detailContent" class="text-muted">Click a point in the chart to inspect its metadata.</div>
                </div>
            </div>
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <h3 class="h6 mb-3">Click-to-confirm helpers</h3>
                    <p class="small text-muted" id="helperIntro">Select a point in the chart to unlock quick lookups.</p>
                    <div class="d-grid gap-2 gap-sm-3 mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="helperDocsButton" disabled>Show nearest articles</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="helperTopicsButton" disabled>Suggest topic labels</button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="helperDriftButton" disabled>Compare neighboring periods</button>
                    </div>
                    <div id="helperDocsResult" class="mb-3"></div>
                    <div id="helperTopicsResult" class="mb-3"></div>
                    <div id="helperDriftResult"></div>
                </div>
            </div>
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <h3 class="h6 mb-2">UMAP tuning tips</h3>
                    <ul class="small">
                        <li>Use <code>metric='cosine'</code> and <code>init='pca'</code>.</li>
                        <li>Try neighbors at 10, 20, 30 and min distances at 0.05, 0.15, 0.4.</li>
                        <li>If clusters look blobby, lower <code>min_dist</code>; if fragmented, raise it.</li>
                        <li>Set a <code>random_state</code> for reproducible layouts.</li>
                        <li>(Optional) Run PCA down to 50 components before UMAP to reduce noise.</li>
                    </ul>
                    <h3 class="h6 mb-2">Make it tell a story</h3>
                    <ul class="small mb-0">
                        <li>Draw a faint line through months in chronological order to highlight big jumps.</li>
                        <li>Add a toggle to color by cluster (e.g., KMeans on the original vectors or their PCA-50 reduction).</li>
                        <li>On hover, surface the top three nearest topics and a few representative articles.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/umap-js@1.3.3/lib/umap-js.min.js"></script>
<script src="https://cdn.plot.ly/plotly-2.27.0.min.js" crossorigin="anonymous"></script>
<script>
(function() {
    const ensureLibs = (callback) => {
        if (window.UMAP && window.Plotly) {
            callback();
        } else {
            setTimeout(() => ensureLibs(callback), 50);
        }
    };

    ensureLibs(() => {
        const form = document.getElementById('controlForm');
        const aggregationSelect = document.getElementById('aggregation');
        const pubnameSelect = document.getElementById('pubname');
        const chartDiv = document.getElementById('chart');
        const chartWrapper = document.getElementById('chartWrapper');
        const statusEl = document.getElementById('status');
        const summaryEl = document.getElementById('summary');
        const detailContent = document.getElementById('detailContent');
        const resetButton = document.getElementById('resetButton');
        const helperIntro = document.getElementById('helperIntro');
        const helperDocsButton = document.getElementById('helperDocsButton');
        const helperTopicsButton = document.getElementById('helperTopicsButton');
        const helperDriftButton = document.getElementById('helperDriftButton');
        const helperDocsResult = document.getElementById('helperDocsResult');
        const helperTopicsResult = document.getElementById('helperTopicsResult');
        const helperDriftResult = document.getElementById('helperDriftResult');
        const clusterSummary = document.getElementById('clusterSummary');
        const clusterSummaryContent = document.getElementById('clusterSummaryContent');
        const clusterSummaryHint = document.getElementById('clusterSummaryHint');
        const colorModeInputs = document.querySelectorAll('input[name="colorMode"]');
        const clusterLegend = document.getElementById('clusterLegend');
        const hoverCard = document.getElementById('hoverCard');
        const panModeButton = document.getElementById('panModeButton');
        const lassoModeButton = document.getElementById('lassoModeButton');
        const yearBrushContainer = document.getElementById('yearBrush');
        const yearBrushLabel = document.getElementById('yearBrushLabel');
        const yearBrushStart = document.getElementById('yearBrushStart');
        const yearBrushEnd = document.getElementById('yearBrushEnd');

        let isLoading = false;
        let latestPlot = null;
        let hasRendered = false;
        let selectedItem = null;
        let selectionStamp = 0;
        let hoverStamp = 0;
        let clusterSummaryStamp = 0;
        let colorMode = 'year';
        let currentDragMode = 'pan';
        let yearBrushRange = null;
        let lastQueryContext = null;
        const insightCache = new Map();
        const defaultHelperMessage = helperIntro ? helperIntro.textContent : 'Select a point in the chart to unlock quick lookups.';
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        const escapeHtml = (value) => {
            const str = value == null ? '' : String(value);
            return str.replace(/[&<>"']/g, (ch) => {
                switch (ch) {
                    case '&': return '&amp;';
                    case '<': return '&lt;';
                    case '>': return '&gt;';
                    case '"': return '&quot;';
                    case "'": return '&#039;';
                }
                return ch;
            });
        };

        const formatPeriodLabel = (period, startDate, fallback) => {
            if (typeof startDate !== 'string' || startDate.length < 4) {
                return fallback || '?';
            }
            const year = startDate.slice(0, 4);
            if (period === 'month' && startDate.length >= 7) {
                const monthIndex = parseInt(startDate.slice(5, 7), 10) - 1;
                if (!Number.isNaN(monthIndex) && monthIndex >= 0 && monthIndex < monthNames.length) {
                    return `${monthNames[monthIndex]} ${year}`;
                }
            }
            if (period === 'year') {
                return year;
            }
            return fallback || startDate;
        };

        const clearHelperOutputs = () => {
            if (helperDocsResult) helperDocsResult.innerHTML = '';
            if (helperTopicsResult) helperTopicsResult.innerHTML = '';
            if (helperDriftResult) helperDriftResult.innerHTML = '';
        };

        const setHelpersEnabled = (enabled, options = {}) => {
            const { clearOutputs = false } = options;
            [helperDocsButton, helperTopicsButton, helperDriftButton].forEach((btn) => {
                if (btn) {
                    btn.disabled = !enabled;
                }
            });
            if (helperIntro) {
                helperIntro.textContent = enabled
                    ? 'Choose an action to pull confirming evidence from the database.'
                    : defaultHelperMessage;
            }
            if (!enabled || clearOutputs) {
                clearHelperOutputs();
            }
        };

        const renderDocsResult = (docs, selection) => {
            if (!Array.isArray(docs) || docs.length === 0) {
                return '<div class="small text-muted">No articles found for this period.</div>';
            }
            let html = '';
            docs.forEach((doc) => {
                const metaParts = [];
                if (doc.date) metaParts.push(doc.date);
                if (doc.pubname) metaParts.push(doc.pubname);
                if (doc.id != null) metaParts.push(`ID ${doc.id}`);
                const metaLine = metaParts.length ? `<div class="small text-muted">${escapeHtml(metaParts.join(' · '))}</div>` : '';
                const summaryText = doc.summary ? escapeHtml(doc.summary) : 'No summary available.';
                const similarityText = typeof doc.similarity === 'number'
                    ? `Cosine similarity ${(doc.similarity).toFixed(3)}`
                    : 'Cosine similarity unavailable';
                html += `
                    <div class="border rounded-3 p-2 mb-2 bg-white">
                        ${metaLine}
                        <div class="small mt-1">${summaryText}</div>
                        <div class="small text-muted mt-1">${escapeHtml(similarityText)}</div>
                    </div>
                `;
            });
            if (selection) {
                const corpusLabel = selection.is_combined
                    ? 'combined corpus'
                    : (selection.pubname ? selection.pubname : 'selected publication');
                html = `<div class="small text-muted mb-2">Top matches within the ${escapeHtml(corpusLabel)}.</div>${html}`;
            }
            return html;
        };

        const renderTopicsResult = (topics) => {
            if (!Array.isArray(topics) || topics.length === 0) {
                return '<div class="small text-muted">No curated topics matched this period.</div>';
            }
            let html = '<ul class="list-group list-group-flush small">';
            topics.forEach((topic) => {
                const similarityText = typeof topic.similarity === 'number'
                    ? `${(topic.similarity * 100).toFixed(1)}% similarity`
                    : 'Similarity unavailable';
                html += `
                    <li class="list-group-item px-2 py-2">
                        <div class="fw-semibold">${escapeHtml(topic.topic || 'Untitled topic')}</div>
                        <div class="text-muted">${escapeHtml(similarityText)}</div>
                    </li>
                `;
            });
            html += '</ul>';
            return html;
        };

        const renderDriftResult = (data, selection) => {
            if (!data || (!data.prev && !data.next)) {
                return '<div class="small text-muted">No neighboring periods were found for this selection.</div>';
            }
            const segments = [];
            if (data.prev) {
                const prevLabel = formatPeriodLabel(selection ? selection.period : null, data.prev.start, data.prev.period_key);
                const distanceText = typeof data.prev.distance === 'number'
                    ? `Cosine distance ${(data.prev.distance).toFixed(3)}`
                    : 'Cosine distance unavailable';
                segments.push(`<div><strong>Previous:</strong> ${escapeHtml(prevLabel)} <span class="text-muted">(${escapeHtml(distanceText)})</span></div>`);
            } else {
                segments.push('<div class="text-muted">No previous period available.</div>');
            }
            if (data.next) {
                const nextLabel = formatPeriodLabel(selection ? selection.period : null, data.next.start, data.next.period_key);
                const distanceText = typeof data.next.distance === 'number'
                    ? `Cosine distance ${(data.next.distance).toFixed(3)}`
                    : 'Cosine distance unavailable';
                segments.push(`<div><strong>Next:</strong> ${escapeHtml(nextLabel)} <span class="text-muted">(${escapeHtml(distanceText)})</span></div>`);
            } else {
                segments.push('<div class="text-muted">No next period available.</div>');
            }
            return `<div class="small">${segments.join('')}</div>`;
        };

        const chooseClusterCount = (count) => {
            if (!count || count < 3) {
                return Math.max(2, count || 1);
            }
            return Math.max(3, Math.min(10, Math.round(Math.sqrt(count / 1.5))));
        };

        const paletteBase = ['#5a189a', '#1d3557', '#b5179e', '#2a9d8f', '#ff6b6b', '#ffb703', '#386641', '#6d597a', '#023047', '#ee6c4d'];

        const generateClusterPalette = (k) => {
            const colors = [];
            for (let i = 0; i < k; i += 1) {
                colors.push(paletteBase[i % paletteBase.length]);
            }
            return colors;
        };

        const runKMeans = (vectors, k, maxIterations = 60) => {
            const pointCount = vectors.length;
            if (!pointCount || k <= 0) {
                return { assignments: new Array(pointCount).fill(0) };
            }
            const dim = vectors[0].length || 0;
            if (dim === 0) {
                return { assignments: new Array(pointCount).fill(0) };
            }
            const actualK = Math.max(1, Math.min(k, pointCount));
            const centroids = [];
            const used = new Set();
            while (centroids.length < actualK) {
                const candidate = Math.floor(Math.random() * pointCount);
                if (!used.has(candidate)) {
                    used.add(candidate);
                    centroids.push(vectors[candidate].slice());
                }
            }
            const assignments = new Array(pointCount).fill(0);
            for (let iter = 0; iter < maxIterations; iter += 1) {
                let changed = false;
                for (let i = 0; i < pointCount; i += 1) {
                    let bestIndex = 0;
                    let bestDist = Infinity;
                    for (let c = 0; c < actualK; c += 1) {
                        let dist = 0;
                        const centroid = centroids[c];
                        const vector = vectors[i];
                        for (let d = 0; d < dim; d += 1) {
                            const diff = vector[d] - centroid[d];
                            dist += diff * diff;
                        }
                        if (dist < bestDist) {
                            bestDist = dist;
                            bestIndex = c;
                        }
                    }
                    if (assignments[i] !== bestIndex) {
                        changed = true;
                        assignments[i] = bestIndex;
                    }
                }
                const counts = new Array(actualK).fill(0);
                const newCentroids = new Array(actualK).fill(null).map(() => new Array(dim).fill(0));
                for (let i = 0; i < pointCount; i += 1) {
                    const cluster = assignments[i];
                    counts[cluster] += 1;
                    const vector = vectors[i];
                    for (let d = 0; d < dim; d += 1) {
                        newCentroids[cluster][d] += vector[d];
                    }
                }
                for (let c = 0; c < actualK; c += 1) {
                    if (counts[c] === 0) {
                        const replacement = vectors[Math.floor(Math.random() * pointCount)];
                        newCentroids[c] = replacement.slice();
                        continue;
                    }
                    for (let d = 0; d < dim; d += 1) {
                        newCentroids[c][d] /= counts[c];
                    }
                }
                for (let c = 0; c < actualK; c += 1) {
                    centroids[c] = newCentroids[c];
                }
                if (!changed) {
                    break;
                }
            }
            return { assignments, k: actualK };
        };

        const ensureClusters = () => {
            if (!latestPlot || !latestPlot.payload) {
                return;
            }
            if (latestPlot.clusterAssignments && latestPlot.clusterAssignments.length === latestPlot.payload.items.length) {
                return;
            }
            const vectors = latestPlot.payload.items.map((item) => item.embedding);
            const desiredK = chooseClusterCount(vectors.length);
            const result = runKMeans(vectors, desiredK);
            latestPlot.clusterAssignments = result.assignments;
            latestPlot.clusterCount = result.k || desiredK;
            latestPlot.clusterPalette = generateClusterPalette(latestPlot.clusterCount);
        };

        const updateClusterLegend = () => {
            if (!clusterLegend) {
                return;
            }
            if (!latestPlot || colorMode !== 'cluster' || !latestPlot.clusterAssignments) {
                clusterLegend.classList.add('d-none');
                clusterLegend.innerHTML = '';
                if (clusterSummaryHint) {
                    clusterSummaryHint.textContent = 'Use lasso selection to inspect a theme.';
                }
                return;
            }
            const counts = new Map();
            latestPlot.clusterAssignments.forEach((cluster) => {
                counts.set(cluster, (counts.get(cluster) || 0) + 1);
            });
            const entries = Array.from(counts.entries()).sort((a, b) => a[0] - b[0]);
            const palette = latestPlot.clusterPalette || [];
            clusterLegend.innerHTML = entries.map(([cluster, count]) => {
                const color = palette[cluster % palette.length];
                return `<div><span class="legend-swatch" style="background:${escapeHtml(color)}"></span>Cluster ${cluster + 1} · ${count}</div>`;
            }).join('');
            clusterLegend.classList.remove('d-none');
            if (clusterSummaryHint) {
                clusterSummaryHint.textContent = `Colouring by ${latestPlot.clusterCount || entries.length} clusters. Lasso a blob to summarise it.`;
            }
        };

        const makeInsightCacheKey = (periodKey) => {
            const ctx = lastQueryContext || {};
            return `${ctx.period || 'year'}|${ctx.aggregation || 'combined'}|${ctx.pubname || ''}|${periodKey}`;
        };

        const loadInsight = async (item) => {
            if (!item || !item.period_key) {
                return null;
            }
            const cacheKey = makeInsightCacheKey(item.period_key);
            if (insightCache.has(cacheKey)) {
                return insightCache.get(cacheKey);
            }
            if (!lastQueryContext) {
                return null;
            }
            const params = new URLSearchParams();
            params.set('period', lastQueryContext.period || 'year');
            params.set('aggregation', lastQueryContext.aggregation || 'combined');
            params.set('period_key', item.period_key);
            if (lastQueryContext.aggregation === 'per_pub' && lastQueryContext.pubname) {
                params.set('pubname', lastQueryContext.pubname);
            }
            try {
                const response = await fetch(`theme_drift.php?action=insight&${params.toString()}`);
                if (!response.ok) {
                    throw new Error(`Insight request failed with status ${response.status}`);
                }
                const payload = await response.json();
                if (!payload.ok) {
                    throw new Error(payload.error || 'Insight request failed');
                }
                const summary = payload.summary || {};
                const insight = {
                    label: payload.period ? payload.period.label : null,
                    monthIso: summary.month_iso || null,
                    docCount: summary.doc_count || 0,
                    phrases: Array.isArray(summary.phrases) ? summary.phrases : [],
                    headlines: Array.isArray(summary.headlines) ? summary.headlines : []
                };
                insightCache.set(cacheKey, insight);
                return insight;
            } catch (err) {
                console.error(err);
                insightCache.set(cacheKey, null);
                return null;
            }
        };

        const showHoverCard = (event, item) => {
            if (!hoverCard || !item) {
                return;
            }
            hoverStamp += 1;
            const stamp = hoverStamp;
            let left = 0;
            let top = 0;
            if (event && chartWrapper) {
                const rect = chartWrapper.getBoundingClientRect();
                left = Math.min(Math.max(event.clientX - rect.left, 12), rect.width - 12);
                top = Math.min(Math.max(event.clientY - rect.top - 12, 12), rect.height - 12);
            }
            hoverCard.style.left = `${left}px`;
            hoverCard.style.top = `${top}px`;
            const baseLabel = item.label || item.period_start || 'Selected period';
            const baseMonth = item.period_start ? item.period_start.slice(0, 7) : '';
            hoverCard.innerHTML = `
                <h4>${escapeHtml(baseLabel)}</h4>
                <div class="muted">${escapeHtml(baseMonth || '')}</div>
                <div class="muted mt-2">Loading representative coverage…</div>
            `;
            hoverCard.classList.add('visible');
            hoverCard.style.display = 'block';
            loadInsight(item).then((insight) => {
                if (hoverStamp !== stamp || !insight) {
                    if (hoverStamp === stamp && !insight) {
                        hoverCard.innerHTML = `
                            <h4>${escapeHtml(baseLabel)}</h4>
                            <div class="muted">${escapeHtml(baseMonth || '')}</div>
                            <div class="muted mt-2">No cached articles yet. Try lassoing and hovering again.</div>
                        `;
                    }
                    return;
                }
                const monthText = insight.monthIso || baseMonth || '';
                const phrasesHtml = insight.phrases && insight.phrases.length
                    ? `<div class="mt-2">${insight.phrases.map((phrase) => `<span class="phrase-badge">${escapeHtml(phrase)}</span>`).join('')}</div>`
                    : '<div class="muted mt-2">No recurring phrases captured.</div>';
                const headlinesHtml = insight.headlines && insight.headlines.length
                    ? `<div class="mt-2">${insight.headlines.map((headline) => `<div class="headline-item">${escapeHtml(headline)}</div>`).join('')}</div>`
                    : '<div class="muted mt-2">No representative headlines available.</div>';
                const docCountText = insight.docCount
                    ? `<div class="muted mt-2">Based on ${insight.docCount} nearby articles.</div>`
                    : '';
                hoverCard.innerHTML = `
                    <h4>${escapeHtml(insight.label || baseLabel)}</h4>
                    <div class="muted">${escapeHtml(monthText)}</div>
                    ${phrasesHtml}
                    ${headlinesHtml}
                    ${docCountText}
                `;
            });
        };

        const hideHoverCard = () => {
            if (!hoverCard) {
                return;
            }
            hoverStamp += 1;
            hoverCard.classList.remove('visible');
            hoverCard.style.display = 'none';
        };

        const renderClusterSummary = async (indices) => {
            if (!clusterSummary || !clusterSummaryContent) {
                return;
            }
            if (!Array.isArray(indices) || indices.length === 0) {
                clusterSummaryContent.innerHTML = 'Lasso a cluster to see which years and phrases dominate.';
                clusterSummary.classList.add('d-none');
                return;
            }
            if (!latestPlot || !latestPlot.payload) {
                return;
            }
            clusterSummary.classList.remove('d-none');
            clusterSummaryContent.innerHTML = '<div class="small text-muted">Crunching selection summary…</div>';
            const stamp = ++clusterSummaryStamp;
            const items = latestPlot.payload.items;
            const insightPromises = indices.map((idx) => {
                const item = items[idx];
                if (!item) {
                    return Promise.resolve(null);
                }
                return loadInsight(item);
            });
            const insights = await Promise.all(insightPromises);
            if (clusterSummaryStamp !== stamp) {
                return;
            }
            const yearCounts = new Map();
            indices.forEach((idx) => {
                const item = items[idx];
                const year = item ? item.year : null;
                if (typeof year === 'number' && !Number.isNaN(year)) {
                    yearCounts.set(year, (yearCounts.get(year) || 0) + 1);
                }
            });
            const sortedYears = Array.from(yearCounts.entries()).sort((a, b) => a[0] - b[0]);
            const maxYearCount = sortedYears.reduce((acc, [, count]) => Math.max(acc, count), 0);
            const histogramHtml = sortedYears.length
                ? `<div class="histogram">${sortedYears.map(([year, count]) => {
                    const height = maxYearCount > 0 ? Math.max(6, Math.round((count / maxYearCount) * 80)) : 6;
                    return `<div class="histogram-bar" style="height: ${height}px;"><span>${escapeHtml(String(year))}</span><em>${count}</em></div>`;
                }).join('')}</div>`
                : '<div class="cluster-summary-empty">Years unavailable for this selection.</div>';
            const phraseCounts = new Map();
            insights.forEach((insight) => {
                if (insight && Array.isArray(insight.phrases)) {
                    insight.phrases.forEach((phrase) => {
                        phraseCounts.set(phrase, (phraseCounts.get(phrase) || 0) + 1);
                    });
                }
            });
            const sortedPhrases = Array.from(phraseCounts.entries()).sort((a, b) => b[1] - a[1]).slice(0, 5);
            const phrasesHtml = sortedPhrases.length
                ? `<div class="mt-3"><strong class="small text-uppercase text-muted">Top phrases</strong><div class="mt-1">${sortedPhrases.map(([phrase, count]) => `<span class="phrase-badge" title="Appears in ${count} periods">${escapeHtml(phrase)}</span>`).join('')}</div></div>`
                : '<div class="mt-3 cluster-summary-empty">No recurring phrases captured yet. Hover points to cache insights, then lasso again.</div>';
            const rangeText = sortedYears.length
                ? `${sortedYears[0][0]} – ${sortedYears[sortedYears.length - 1][0]}`
                : 'n/a';
            clusterSummaryContent.innerHTML = `
                <div class="small mb-2">Selected <strong>${indices.length}</strong> periods covering <strong>${escapeHtml(rangeText)}</strong>.</div>
                <div><strong class="small text-uppercase text-muted">Year distribution</strong>${histogramHtml}</div>
                ${phrasesHtml}
                <div class="small text-muted mt-3">Tip: hover dots to populate the cache, then re-lasso for richer summaries.</div>
            `;
        };

        const setDragMode = (mode) => {
            const nextMode = mode === 'lasso' ? 'lasso' : 'pan';
            currentDragMode = nextMode;
            if (panModeButton) panModeButton.classList.toggle('active', nextMode === 'pan');
            if (lassoModeButton) lassoModeButton.classList.toggle('active', nextMode === 'lasso');
            if (
                hasRendered &&
                chartDiv &&
                typeof Plotly !== 'undefined' &&
                typeof Plotly.relayout === 'function' &&
                chartDiv._fullLayout
            ) {
                Plotly.relayout(chartDiv, { dragmode: nextMode });
            }
        };

        const updateAggregationState = () => {
            const isCombined = aggregationSelect.value !== 'per_pub';
            pubnameSelect.disabled = isCombined;
            if (isCombined) {
                pubnameSelect.value = '';
            }
        };

        const resetDetail = () => {
            detailContent.innerHTML = 'Click a point in the chart to inspect its metadata.';
            selectedItem = null;
            selectionStamp += 1;
            setHelpersEnabled(false);
        };

        const updateSummary = (payload) => {
            if (!payload || !Array.isArray(payload.items) || payload.items.length === 0) {
                summaryEl.innerHTML = '';
                return;
            }
            const totalArticles = payload.items.reduce((acc, item) => acc + (item.article_count || 0), 0);
            const totalTokens = payload.items.reduce((acc, item) => acc + (item.token_count || 0), 0);
            const rangeText = (payload.min_year && payload.max_year) ? `${payload.min_year}–${payload.max_year}` : 'selected range';
            const label = payload.period === 'month' ? 'months' : 'years';
            summaryEl.innerHTML = `
                <div class="alert alert-secondary" role="alert">
                    Loaded <strong>${payload.count}</strong> ${label} covering <strong>${rangeText}</strong>.<br>
                    Articles represented: <strong>${totalArticles.toLocaleString()}</strong> · Tokens aggregated: <strong>${totalTokens.toLocaleString()}</strong>.
                </div>`;
        };

        const getMarkerOpacities = () => {
            if (!latestPlot || !Array.isArray(latestPlot.yearValues)) {
                return [];
            }
            if (!yearBrushRange) {
                return new Array(latestPlot.yearValues.length).fill(0.88);
            }
            const { start, end } = yearBrushRange;
            return latestPlot.yearValues.map((year) => {
                if (typeof year !== 'number' || Number.isNaN(year)) {
                    return 0.15;
                }
                return (year >= start && year <= end) ? 0.95 : 0.1;
            });
        };

        const buildPlotData = () => {
            if (!latestPlot) {
                return { data: [], layout: {} };
            }
            const { coords, payload, yearValues, minYear, maxYear, chronologicalOrder } = latestPlot;
            const xs = coords.map((coord) => coord[0]);
            const ys = coords.map((coord) => coord[1]);
            const opacities = getMarkerOpacities();
            const lineXs = [];
            const lineYs = [];
            chronologicalOrder.forEach((idx) => {
                const coord = coords[idx];
                lineXs.push(coord[0]);
                lineYs.push(coord[1]);
            });
            const pathTrace = {
                type: 'scattergl',
                mode: 'lines',
                x: lineXs,
                y: lineYs,
                line: { color: 'rgba(73, 80, 87, 0.35)', width: 1.2 },
                hoverinfo: 'skip',
                showlegend: false,
                name: 'Chronological path'
            };
            let marker;
            if (colorMode === 'cluster') {
                ensureClusters();
                const palette = latestPlot.clusterPalette || [];
                const colors = (latestPlot.clusterAssignments || []).map((cluster) => palette[cluster % palette.length] || '#666');
                marker = {
                    size: 9,
                    color: colors,
                    opacity: opacities,
                    line: { width: 0.5, color: 'rgba(0,0,0,0.25)' },
                    showscale: false
                };
            } else {
                marker = {
                    size: 9,
                    color: yearValues,
                    opacity: opacities,
                    colorscale: 'Viridis',
                    colorbar: { title: 'Year' },
                    cmin: minYear,
                    cmax: maxYear,
                    line: { width: 0.5, color: 'rgba(0,0,0,0.3)' }
                };
            }
            const scatterTrace = {
                type: 'scattergl',
                mode: 'markers',
                x: xs,
                y: ys,
                hoverinfo: 'skip',
                marker,
                customdata: payload.items.map((item, idx) => [idx, item.period_key || '', item.period_start || '', item.period_end || ''])
            };
            const layout = {
                dragmode: currentDragMode,
                hovermode: 'closest',
                margin: { l: 40, r: 20, t: 20, b: 40 },
                paper_bgcolor: '#f8f9fa',
                plot_bgcolor: '#f8f9fa',
                xaxis: { title: 'UMAP 1', showgrid: false, zeroline: false },
                yaxis: { title: 'UMAP 2', showgrid: false, zeroline: false },
                height: 600,
                showlegend: false
            };
            return { data: [pathTrace, scatterTrace], layout };
        };

        const drawPlot = async () => {
            if (!chartDiv || !latestPlot) {
                return;
            }
            const { data, layout } = buildPlotData();
            if (!hasRendered) {
                await Plotly.newPlot(chartDiv, data, layout, { responsive: true, displaylogo: false });
                hasRendered = true;
                attachPlotEvents();
            } else {
                await Plotly.react(chartDiv, data, layout, { responsive: true, displaylogo: false });
            }
            updateClusterLegend();
        };

        const handlePlotClick = (eventData) => {
            if (!eventData || !eventData.points || !eventData.points.length || !latestPlot) {
                return;
            }
            const point = eventData.points[0];
            const idx = point.customdata ? point.customdata[0] : point.pointIndex;
            const item = latestPlot.payload.items[idx];
            if (!item) {
                return;
            }
            const selection = {
                id: item.id != null ? item.id : null,
                period_key: item.period_key || '',
                period_start: item.period_start || null,
                period_end: item.period_end || null,
                label: item.label || item.period_start || 'Selected period',
                is_combined: Boolean(item.is_combined),
                pubname: item.pubname || null,
                period: latestPlot.payload.period || 'year'
            };
            selectionStamp += 1;
            selectedItem = selection;
            setHelpersEnabled(true, { clearOutputs: true });
            const publication = item.is_combined ? 'Combined corpus' : (item.pubname || 'Unknown publication');
            detailContent.innerHTML = `
                <div><strong>${escapeHtml(selection.label)}</strong></div>
                <div class="small text-muted">${escapeHtml(publication)}</div>
                <hr>
                <div><strong>Period:</strong> ${escapeHtml(selection.period_start || '?')} → ${escapeHtml(selection.period_end || '?')}</div>
                <div><strong>Articles:</strong> ${item.article_count ? item.article_count.toLocaleString() : 'n/a'}</div>
                <div><strong>Tokens:</strong> ${item.token_count ? item.token_count.toLocaleString() : 'n/a'}</div>
                <div><strong>Model:</strong> ${escapeHtml(item.model || 'unknown')} &middot; dim ${item.dim || 'n/a'}</div>
                <div><strong>Database key:</strong> ${escapeHtml(selection.period_key || String(item.id || '?'))}</div>
            `;
        };

        const handlePlotHover = (eventData) => {
            if (!eventData || !eventData.points || !eventData.points.length || !latestPlot) {
                return;
            }
            const point = eventData.points[0];
            const idx = point.customdata ? point.customdata[0] : point.pointIndex;
            const item = latestPlot.payload.items[idx];
            showHoverCard(eventData.event, item);
        };

        const handlePlotUnhover = () => {
            hideHoverCard();
        };

        const handlePlotSelected = (eventData) => {
            if (!eventData || !eventData.points) {
                renderClusterSummary([]);
                return;
            }
            const indices = Array.from(new Set(eventData.points.map((pt) => pt.customdata ? pt.customdata[0] : pt.pointIndex).filter((idx) => idx != null)));
            renderClusterSummary(indices);
        };

        const handlePlotDeselected = () => {
            renderClusterSummary([]);
        };

        const attachPlotEvents = () => {
            chartDiv.on('plotly_click', handlePlotClick);
            chartDiv.on('plotly_hover', handlePlotHover);
            chartDiv.on('plotly_unhover', handlePlotUnhover);
            chartDiv.on('plotly_selected', handlePlotSelected);
            chartDiv.on('plotly_deselect', handlePlotDeselected);
        };

        const updateYearBrushControls = () => {
            if (!yearBrushContainer || !latestPlot) {
                return;
            }
            const { minYear, maxYear } = latestPlot;
            if (typeof minYear !== 'number' || typeof maxYear !== 'number' || !Number.isFinite(minYear) || !Number.isFinite(maxYear) || minYear === maxYear) {
                yearBrushContainer.classList.add('d-none');
                yearBrushRange = null;
                if (yearBrushLabel) {
                    yearBrushLabel.textContent = 'Showing all years';
                }
                return;
            }
            yearBrushContainer.classList.remove('d-none');
            yearBrushStart.min = minYear;
            yearBrushStart.max = maxYear;
            yearBrushEnd.min = minYear;
            yearBrushEnd.max = maxYear;
            yearBrushStart.value = minYear;
            yearBrushEnd.value = maxYear;
            yearBrushRange = null;
            if (yearBrushLabel) {
                yearBrushLabel.textContent = 'Showing all years';
            }
        };

        const handleYearBrushChange = () => {
            if (!latestPlot) {
                return;
            }
            const startVal = Number(yearBrushStart.value);
            const endVal = Number(yearBrushEnd.value);
            if (Number.isNaN(startVal) || Number.isNaN(endVal)) {
                return;
            }
            const start = Math.min(startVal, endVal);
            const end = Math.max(startVal, endVal);
            if (start <= latestPlot.minYear && end >= latestPlot.maxYear) {
                yearBrushRange = null;
                if (yearBrushLabel) {
                    yearBrushLabel.textContent = 'Showing all years';
                }
            } else {
                yearBrushRange = { start, end };
                if (yearBrushLabel) {
                    yearBrushLabel.textContent = `Highlighting ${start} – ${end}`;
                }
            }
            drawPlot();
        };

        const renderPlot = async (payload) => {
            if (!payload || !payload.items || payload.items.length === 0) {
                Plotly.purge(chartDiv);
                statusEl.textContent = 'No embeddings matched the current filters.';
                updateSummary(null);
                resetDetail();
                renderClusterSummary([]);
                updateClusterLegend();
                return;
            }
            const neighborsInput = document.getElementById('neighbors');
            const minDistInput = document.getElementById('minDist');
            const nNeighbors = Math.max(5, Math.min(60, parseInt(neighborsInput.value, 10) || 15));
            const minDist = Math.max(0.01, Math.min(0.99, parseFloat(minDistInput.value) || 0.15));
            const vectors = payload.items.map((item) => item.embedding);
            statusEl.textContent = `Computing UMAP projection for ${vectors.length} vectors…`;
            await new Promise((resolve) => setTimeout(resolve, 50));
            const umap = new window.UMAP({ nNeighbors, minDist, nComponents: 2, random: Math.random });
            const coords = await umap.fitAsync(vectors);
            const fallbackYear = (typeof payload.min_year === 'number' && !Number.isNaN(payload.min_year)) ? payload.min_year : 0;
            const yearValues = payload.items.map((item) => (typeof item.year === 'number' && !Number.isNaN(item.year) ? item.year : fallbackYear));
            const chronologicalOrder = payload.items
                .map((item, idx) => {
                    const ts = Date.parse(item.period_start || item.period_end || '')
                        || (payload.period === 'year' && item.year ? Date.parse(`${item.year}-01-01`) : NaN);
                    return { idx, ts: Number.isNaN(ts) ? idx : ts };
                })
                .sort((a, b) => a.ts - b.ts)
                .map((entry) => entry.idx);
            const minYear = (typeof payload.min_year === 'number' && !Number.isNaN(payload.min_year)) ? payload.min_year : Math.min(...yearValues);
            const maxYear = (typeof payload.max_year === 'number' && !Number.isNaN(payload.max_year)) ? payload.max_year : Math.max(...yearValues);
            latestPlot = {
                payload,
                coords,
                yearValues,
                minYear,
                maxYear,
                chronologicalOrder,
                clusterAssignments: null,
                clusterCount: 0,
                clusterPalette: []
            };
            updateSummary(payload);
            resetDetail();
            renderClusterSummary([]);
            hideHoverCard();
            updateYearBrushControls();
            await drawPlot();
            statusEl.textContent = `Rendered ${payload.count} periods. Toggle the colour mode to compare chronology and themes.`;
        };

        const loadData = async () => {
            if (isLoading) {
                return;
            }
            isLoading = true;
            statusEl.textContent = 'Loading period embeddings…';
            summaryEl.innerHTML = '';
            resetDetail();
            renderClusterSummary([]);
            updateClusterLegend();
            hideHoverCard();
            insightCache.clear();
            yearBrushRange = null;
            setDragMode('pan');

            const formData = new FormData(form);
            const periodValue = formData.get('period') || 'year';
            const aggregationValue = aggregationSelect.value || 'combined';
            const params = new URLSearchParams();
            params.set('period', periodValue);
            params.set('aggregation', aggregationValue);
            const startYearVal = formData.get('start_year');
            if (startYearVal) params.set('start_year', startYearVal);
            const endYearVal = formData.get('end_year');
            if (endYearVal) params.set('end_year', endYearVal);
            const limitVal = formData.get('limit');
            if (limitVal) params.set('limit', limitVal);
            let pubnameValue = '';
            if (aggregationValue === 'per_pub') {
                pubnameValue = formData.get('pubname') || '';
                if (pubnameValue) {
                    params.set('pubname', pubnameValue);
                }
            }
            lastQueryContext = { period: periodValue, aggregation: aggregationValue, pubname: pubnameValue };

            try {
                const response = await fetch(`theme_drift.php?action=data&${params.toString()}`);
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                const payload = await response.json();
                if (!payload.ok) {
                    throw new Error(payload.error || 'Unknown error');
                }
                statusEl.textContent = 'Embeddings loaded. Preparing visualization…';
                await renderPlot(payload);
            } catch (err) {
                console.error(err);
                Plotly.purge(chartDiv);
                statusEl.textContent = 'Failed to load embeddings: ' + err.message;
                summaryEl.innerHTML = '';
                resetDetail();
                renderClusterSummary([]);
            } finally {
                isLoading = false;
            }
        };

        const runHelper = async (type) => {
            if (!selectedItem) {
                return;
            }
            const stamp = selectionStamp;
            const params = new URLSearchParams();
            params.set('period', selectedItem.period);
            params.set('aggregation', selectedItem.is_combined ? 'combined' : 'per_pub');
            params.set('period_key', selectedItem.period_key);
            if (!selectedItem.is_combined && selectedItem.pubname) {
                params.set('pubname', selectedItem.pubname);
            }
            if (type === 'docs') params.set('action', 'nearest_docs');
            if (type === 'topics') params.set('action', 'topic_labels');
            if (type === 'drift') params.set('action', 'drift');
            try {
                const response = await fetch(`theme_drift.php?${params.toString()}`);
                if (!response.ok) {
                    throw new Error(`Helper request failed with status ${response.status}`);
                }
                const payload = await response.json();
                if (!payload.ok) {
                    throw new Error(payload.error || 'Unknown helper error');
                }
                if (selectionStamp !== stamp) {
                    return;
                }
                if (type === 'docs') helperDocsResult.innerHTML = renderDocsResult(payload.docs || [], selectedItem);
                if (type === 'topics') helperTopicsResult.innerHTML = renderTopicsResult(payload.topics || []);
                if (type === 'drift') helperDriftResult.innerHTML = renderDriftResult(payload, selectedItem);
            } catch (err) {
                console.error(err);
                const message = `<div class="small text-danger">${escapeHtml(err.message)}</div>`;
                if (type === 'docs') helperDocsResult.innerHTML = message;
                if (type === 'topics') helperTopicsResult.innerHTML = message;
                if (type === 'drift') helperDriftResult.innerHTML = message;
            }
        };

        setHelpersEnabled(false);
        updateAggregationState();

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            loadData();
        });

        aggregationSelect.addEventListener('change', () => {
            updateAggregationState();
        });

        if (helperDocsButton) helperDocsButton.addEventListener('click', () => runHelper('docs'));
        if (helperTopicsButton) helperTopicsButton.addEventListener('click', () => runHelper('topics'));
        if (helperDriftButton) helperDriftButton.addEventListener('click', () => runHelper('drift'));

        if (panModeButton) panModeButton.addEventListener('click', () => setDragMode('pan'));
        if (lassoModeButton) lassoModeButton.addEventListener('click', () => setDragMode('lasso'));
        setDragMode('pan');

        colorModeInputs.forEach((input) => {
            input.addEventListener('change', (event) => {
                if (!event.target.checked) {
                    return;
                }
                colorMode = event.target.value === 'cluster' ? 'cluster' : 'year';
                if (colorMode === 'cluster') {
                    ensureClusters();
                }
                updateClusterLegend();
                drawPlot();
            });
        });

        if (yearBrushStart && yearBrushEnd) {
            yearBrushStart.addEventListener('input', handleYearBrushChange);
            yearBrushEnd.addEventListener('input', handleYearBrushChange);
        }

        resetButton.addEventListener('click', () => {
            document.getElementById('period').value = 'year';
            aggregationSelect.value = 'combined';
            updateAggregationState();
            document.getElementById('startYear').value = <?= json_encode($defaultStartYear) ?>;
            document.getElementById('endYear').value = <?= json_encode($defaultEndYear) ?>;
            document.getElementById('limit').value = 360;
            document.getElementById('neighbors').value = 15;
            document.getElementById('minDist').value = 0.15;
            loadData();
        });

        loadData();
    });
})();

</script>
</body>
</html>
