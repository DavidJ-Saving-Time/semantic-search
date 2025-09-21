<?php

// index.php — Semantic search (inferred topics only) for web (Apache + PHP)
// - Uses OpenAI embeddings (from env OPENAI_API_KEY)
// - Postgres via PDO (PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD)
// - Bootstrap 5 UI
// - Optional query embedding cache (table DDL below)
// No CSRF/rate limiting (local dev as requested).


ini_set('display_errors', '0'); // keep UI clean; inspect server logs for details
setlocale(LC_NUMERIC, 'C');     // ensure "." decimal separator for floats

// -------------------------- Config (env) --------------------------
$OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: '';
$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';
$OPENAI_MODEL = getenv('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-large';
$VOYAGE_API_KEY = getenv('VOYAGE_API_KEY') ?: '';
$VOYAGE_RERANK_MODEL = getenv('VOYAGE_RERANK_MODEL') ?: 'rerank-2.5';

// Optional Voyage timeout (env)
$VOYAGE_TIMEOUT = (int)(getenv('VOYAGE_TIMEOUT_SECS') ?: 8);

// Optional timeouts (env)
$OPENAI_TIMEOUT = (int)(getenv('OPENAI_TIMEOUT_SECS') ?: 8);
$PG_STMT_TIMEOUT = (int)(getenv('PG_STATEMENT_TIMEOUT_MS') ?: 0);

// Server-side defaults (you can surface later in UI)
$DEF_LIMIT  = 10;
$DEF_W_SIM  = 0.9;
$DEF_W_TOP  = 0.1;
$DEF_THRESH = 0.35;
$DEF_K      = 3;

// -------------------------- Helpers --------------------------
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clamp_int($v, int $min, int $max, int $fallback): int
{
    if (!is_numeric($v)) {
        return $fallback;
    }
    $vi = (int)$v;
    return max($min, min($max, $vi));
}

function normalize_query_key(string $q): string
{
    $q = strtolower(trim($q));
    $q = preg_replace('/\s+/u', ' ', $q);
    return $q;
}

function vector_literal(array $floats): string
{
    // avoid scientific notation; implode raw strings
    $out = [];
    foreach ($floats as $f) {
        // cast to float then format without thousands sep, with dot decimal
        $out[] = rtrim(rtrim(number_format((float)$f, 8, '.', ''), '0'), '.'); // trim trailing zeros
    }
    return '[' . implode(',', $out) . ']';
}

// -------------------------- OpenAI Embeddings --------------------------
function openai_embed(string $apiKey, string $text, string $model, int $timeoutSec = 8): array
{
    $payload = json_encode(['input' => $text, 'model' => $model], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => max(2, $timeoutSec),
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http < 200 || $http >= 300) {
        throw new RuntimeException('Embedding request failed: HTTP ' . $http . ($err ? " ($err)" : ''));
    }
    $j = json_decode($resp, true);
    if (!is_array($j) || !isset($j['data'][0]['embedding'])) {
        throw new RuntimeException('Invalid embedding response');
    }
    return $j['data'][0]['embedding'];
}

function voyage_rerank(string $apiKey, string $query, array $documents, string $model, int $timeoutSec = 8): array
{
    if ($apiKey === '') {
        throw new RuntimeException('Voyage API key is not configured.');
    }

    $payload = json_encode([
        'query' => $query,
        'documents' => $documents,
        'model' => $model,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.voyageai.com/v1/rerank');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: ' . 'Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => max(2, $timeoutSec),
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http < 200 || $http >= 300) {
        throw new RuntimeException('Voyage rerank request failed: HTTP ' . $http . ($err ? " ($err)" : ''));
    }

    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid Voyage rerank response.');
    }

    $items = $decoded['results'] ?? $decoded['data'] ?? null;
    if (!is_array($items)) {
        throw new RuntimeException('Voyage rerank response missing results.');
    }

    return $items;
}

// -------------------------- Postgres --------------------------
function pg_pdo(string $host, string $port, string $db, string $user, string $pass, int $stmtTimeoutMs): PDO
{
    $app = "semantic_search_web";
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--application_name={$app}'";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);

    error_log("[semantic_search] stmt_timeout=" . $pdo->query("SHOW statement_timeout")->fetchColumn());
    $t = microtime(true);
    $pdo->query("SELECT 1")->fetchColumn();
    error_log("[semantic_search] select1_ms=" . (int)round((microtime(true) - $t) * 1000));

    $pdo->exec("SET jit = off");

    return $pdo;
}

// Returns cached vector or null. Swallows missing-table errors.
function cache_get(PDO $pdo, string $qkey): ?array
{
    try {
        $st = $pdo->prepare("SELECT emb FROM query_cache WHERE query_key = :qkey");
        $st->execute([':qkey' => $qkey]);
        $row = $st->fetch();
        if ($row && isset($row['emb']) && is_string($row['emb'])) {
            // emb stored as text[]? We’ll store as JSON text for safety.
            $arr = json_decode($row['emb'], true);
            if (is_array($arr)) {
                return $arr;
            }
        }
    } catch (Throwable $e) { /* ignore for local dev */
    }
    return null;
}

function cache_put(PDO $pdo, string $qkey, array $embedding): void
{
    try {
        $st = $pdo->prepare("INSERT INTO query_cache(query_key, emb, created_at) VALUES (:qkey, :emb, NOW())
                             ON CONFLICT (query_key) DO UPDATE SET emb = EXCLUDED.emb, created_at = NOW()");
        $st->execute([':qkey' => $qkey, ':emb' => json_encode($embedding)]);
    } catch (Throwable $e) { /* ignore for local dev */
    }
}

/**
 * Opens a pgsql connection with common settings applied.
 *
 * @return PgSql\Connection|resource
 */
function pg_connect_with_options(string $host, string $port, string $db, string $user, string $pass)
{
    $connStr = sprintf(
        'host=%s port=%s dbname=%s user=%s',
        $host,
        $port,
        $db,
        $user
    );
    if ($pass !== '') {
        $connStr .= ' password=' . $pass;
    }

    $pgconn = pg_connect($connStr);
    if ($pgconn === false) {
        throw new RuntimeException('pg_connect failed: ' . pg_last_error());
    }

    pg_query($pgconn, 'SET jit = off');
    pg_query($pgconn, 'SET statement_timeout = 0');

    return $pgconn;
}

function heatmap_cache_key(string $normalizedQuery, string $from, string $to, string $granularity, int $k, string $model): string
{
    return implode('|', [
        'heatmap',
        strtolower($model),
        strtolower($granularity),
        $from,
        $to,
        'k=' . $k,
        $normalizedQuery,
    ]);
}

function heatmap_color_for_ratio(float $ratio): array
{
    $ratio = max(0.0, min(1.0, $ratio));
    $start = [227, 236, 255]; // soft blue
    $end   = [13, 110, 253];  // bootstrap primary
    $r = (int)round($start[0] + ($end[0] - $start[0]) * $ratio);
    $g = (int)round($start[1] + ($end[1] - $start[1]) * $ratio);
    $b = (int)round($start[2] + ($end[2] - $start[2]) * $ratio);
    $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
    $useLightText = $ratio >= 0.55;
    return [$hex, $useLightText];
}

function run_heatmap_query($pgconn, string $vecLiteral, string $from, string $to, string $granularity, int $k): array
{
    $granularity = $granularity === 'year' ? 'year' : 'month';
    $k = max(1, (int)$k);

    if ($granularity === 'month') {
        $sql = <<<SQL
WITH q AS (
  SELECT ($1::public.halfvec(3072)) AS q
),
scored AS (
  SELECT
    to_char(d.date, 'YYYY-MM') AS period_key,
    1 - (d.embedding_hv <=> q.q) AS sim,
    ROW_NUMBER() OVER (
      PARTITION BY to_char(d.date, 'YYYY-MM')
      ORDER BY d.embedding_hv <=> q.q
    ) AS rn
  FROM public.docs d, q
  WHERE d.embedding_hv IS NOT NULL
    AND d.date >= $2::date
    AND d.date < $3::date + INTERVAL '1 day'
)
SELECT
  period_key,
  AVG(sim) AS score,
  COUNT(*) FILTER (WHERE rn <= $4::int) AS k_count
FROM scored
WHERE rn <= $4::int
GROUP BY period_key
ORDER BY period_key;
SQL;
    } else {
        $sql = <<<SQL
WITH q AS (
  SELECT ($1::public.halfvec(3072)) AS q
),
scored AS (
  SELECT
    to_char(d.date, 'YYYY') AS period_key,
    1 - (d.embedding_hv <=> q.q) AS sim,
    ROW_NUMBER() OVER (
      PARTITION BY to_char(d.date, 'YYYY')
      ORDER BY d.embedding_hv <=> q.q
    ) AS rn
  FROM public.docs d, q
  WHERE d.embedding_hv IS NOT NULL
    AND d.date >= $2::date
    AND d.date < $3::date + INTERVAL '1 day'
)
SELECT
  period_key,
  AVG(sim) AS score,
  COUNT(*) FILTER (WHERE rn <= $4::int) AS k_count
FROM scored
WHERE rn <= $4::int
GROUP BY period_key
ORDER BY period_key;
SQL;
    }

    $res = pg_query_params($pgconn, $sql, [$vecLiteral, $from, $to, $k]);
    if ($res === false) {
        throw new RuntimeException('Heatmap query failed: ' . pg_last_error($pgconn));
    }

    $rows = pg_fetch_all($res) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $periodKey = isset($row['period_key']) ? (string)$row['period_key'] : '';
        if ($periodKey === '') {
            continue;
        }
        $score = isset($row['score']) ? (float)$row['score'] : null;
        $kCount = isset($row['k_count']) ? (int)$row['k_count'] : 0;
        $out[] = [
            'period_key' => $periodKey,
            'score' => $score,
            'k_count' => $kCount,
        ];
    }

    return $out;
}

if (($_GET['action'] ?? '') === 'heatmap_bucket') {
    header('Content-Type: application/json; charset=utf-8');
    $status = 200;
    $response = ['ok' => false];
    $pgconn = null;

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Invalid request method.');
        }

        $q = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
        if ($q === '' || mb_strlen($q) > 512) {
            throw new RuntimeException('Invalid query.');
        }

        $granularity = isset($_POST['granularity']) ? strtolower((string)$_POST['granularity']) : 'month';
        if (!in_array($granularity, ['month', 'year'], true)) {
            throw new RuntimeException('Invalid granularity.');
        }

        $periodKey = isset($_POST['period_key']) ? trim((string)$_POST['period_key']) : '';
        $periodStart = null;
        $periodEndExclusive = null;
        if ($granularity === 'month') {
            if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
                throw new RuntimeException('Invalid period key.');
            }
            $periodStart = DateTimeImmutable::createFromFormat('!Y-m', $periodKey, new DateTimeZone('UTC'));
            if ($periodStart === false) {
                throw new RuntimeException('Unable to parse period.');
            }
            $periodEndExclusive = $periodStart->modify('+1 month');
        } else {
            if (!preg_match('/^\d{4}$/', $periodKey)) {
                throw new RuntimeException('Invalid period key.');
            }
            $periodStart = DateTimeImmutable::createFromFormat('!Y', $periodKey, new DateTimeZone('UTC'));
            if ($periodStart === false) {
                throw new RuntimeException('Unable to parse period.');
            }
            $periodEndExclusive = $periodStart->modify('+1 year');
        }

        if (!$periodEndExclusive) {
            throw new RuntimeException('Invalid period range.');
        }

        $rangeFromStr = isset($_POST['range_from']) ? trim((string)$_POST['range_from']) : '';
        $rangeToStr   = isset($_POST['range_to']) ? trim((string)$_POST['range_to']) : '';
        $rangeFrom = $rangeFromStr !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $rangeFromStr, new DateTimeZone('UTC')) : null;
        $rangeTo   = $rangeToStr !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $rangeToStr, new DateTimeZone('UTC')) : null;
        if ($rangeFromStr !== '' && $rangeFrom === false) {
            throw new RuntimeException('Invalid range start.');
        }
        if ($rangeToStr !== '' && $rangeTo === false) {
            throw new RuntimeException('Invalid range end.');
        }
        if ($rangeFrom && $periodEndExclusive <= $rangeFrom) {
            throw new RuntimeException('Requested period precedes range.');
        }
        if ($rangeTo && $periodStart > $rangeTo) {
            throw new RuntimeException('Requested period exceeds range.');
        }

        $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD, $PG_STMT_TIMEOUT);
        $normalized = normalize_query_key($q);
        $embedding = cache_get($pdo, $normalized);
        if ($embedding === null) {
            if ($OPENAI_API_KEY === '') {
                throw new RuntimeException('OPENAI_API_KEY is not configured.');
            }
            $embedding = openai_embed($OPENAI_API_KEY, $q, $OPENAI_MODEL, $OPENAI_TIMEOUT);
            cache_put($pdo, $normalized, $embedding);
        }

        $vecLiteral = vector_literal($embedding);
        $pgconn = pg_connect_with_options($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD);

        $bucketStartStr = $periodStart->format('Y-m-d');
        $bucketEndExclusiveStr = $periodEndExclusive->format('Y-m-d');
        $bucketEndInclusiveStr = $periodEndExclusive->modify('-1 day')->format('Y-m-d');

        $sql = <<<SQL
SELECT
  d.id,
  d.pubname,
  d.date::text AS date,
  d.summary_clean AS summary,
  d.meta->>'title' AS title,
  p.page_id,
  1 - (d.embedding_hv <=> ($1::public.halfvec(3072))) AS score
FROM public.docs d
LEFT JOIN public.pages p
  ON p.issue = d.issue AND p.page = d.page
WHERE d.embedding_hv IS NOT NULL
  AND d.date >= $2::date
  AND d.date < $3::date
ORDER BY d.embedding_hv <=> ($1::public.halfvec(3072))
LIMIT 5;
SQL;

        $res = pg_query_params($pgconn, $sql, [$vecLiteral, $bucketStartStr, $bucketEndExclusiveStr]);
        if ($res === false) {
            throw new RuntimeException('Failed to fetch articles.');
        }

        $rows = pg_fetch_all($res) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $summary = trim((string)($row['summary'] ?? ''));
            if (mb_strlen($summary) > 600) {
                $summary = rtrim(mb_substr($summary, 0, 600)) . '…';
            }

            $pageId = null;
            if (isset($row['page_id']) && $row['page_id'] !== null && $row['page_id'] !== '') {
                $pageId = (int)$row['page_id'];
                if ($pageId <= 0) {
                    $pageId = null;
                }
            }

            $items[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'pubname' => $row['pubname'] ?? null,
                'date' => $row['date'] ?? null,
                'title' => $row['title'] ?? null,
                'summary' => $summary,
                'score' => isset($row['score']) ? (float)$row['score'] : null,
                'page_id' => $pageId,
            ];
        }

        $response = [
            'ok' => true,
            'items' => $items,
            'period' => [
                'key' => $periodKey,
                'label' => $periodStart->format($granularity === 'month' ? 'F Y' : 'Y'),
                'start' => $bucketStartStr,
                'end' => $bucketEndInclusiveStr,
            ],
            'granularity' => $granularity,
        ];
    } catch (Throwable $e) {
        $status = 400;
        $response = ['ok' => false, 'error' => $e->getMessage()];
        error_log('[semantic_search][heatmap_bucket] ' . $e->getMessage());
    } finally {
        if ($pgconn) {
            pg_close($pgconn);
        }
    }

    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$selectedPub = isset($_POST['pubname']) ? trim((string)$_POST['pubname']) : '';
$selectedYear = isset($_POST['year']) ? trim((string)$_POST['year']) : '';
if ($selectedYear !== '' && !preg_match('/^\d{4}$/', $selectedYear)) {
    $selectedYear = '';
}
$selectedGenre = isset($_POST['genre']) ? trim((string)$_POST['genre']) : '';
if ($selectedGenre !== '' && mb_strlen($selectedGenre) > 100) {
    $selectedGenre = '';
}

$pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD, 5000); // 5s timeout example

$stmt = $pdo->query("
    SELECT DISTINCT pubname
    FROM docs
    WHERE pubname IS NOT NULL AND pubname <> ''
    ORDER BY pubname
");
$pubnames = $stmt->fetchAll(PDO::FETCH_COLUMN);


$sqlCounts = "
  SELECT pubname AS k, COUNT(*)::int AS cnt
  FROM docs
  WHERE pubname IS NOT NULL AND pubname <> ''
  GROUP BY pubname
";
$counts = [];
$total  = 0;
foreach ($pdo->query($sqlCounts, PDO::FETCH_ASSOC) as $r) {
    $counts[$r['k']] = (int)$r['cnt'];
    $total += (int)$r['cnt'];
}

$years = [];
$yearCounts = [];
$yearTotal = 0;
$yearRows = $pdo->query("
    SELECT date_part('year', date)::int AS year, COUNT(*)::int AS cnt
    FROM docs
    WHERE date IS NOT NULL
    GROUP BY date_part('year', date)::int
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($yearRows as $row) {
    $yearValue = trim((string)($row['year'] ?? ''));
    if ($yearValue === '') {
        continue;
    }
    $years[] = $yearValue;
    $yearCounts[$yearValue] = (int)$row['cnt'];
    $yearTotal += (int)$row['cnt'];
}
if ($selectedYear !== '' && !in_array($selectedYear, $years, true)) {
    $selectedYear = '';
}

$genres = [];
$genreCounts = [];
$genreLookup = [];
$genreRows = $pdo->query("
    SELECT MIN(btrim(g.val)) AS genre, COUNT(*)::int AS cnt
    FROM docs
    CROSS JOIN LATERAL unnest(genre) AS g(val)
    WHERE g.val IS NOT NULL AND btrim(g.val) <> ''
    GROUP BY lower(btrim(g.val))
    ORDER BY genre
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($genreRows as $row) {
    $genreLabel = trim((string)($row['genre'] ?? ''));
    if ($genreLabel === '') {
        continue;
    }
    $genres[] = $genreLabel;
    $genreCounts[$genreLabel] = (int)$row['cnt'];
    $genreLookup[strtolower($genreLabel)] = $genreLabel;
}
if ($selectedGenre !== '') {
    $genreKey = strtolower($selectedGenre);
    if (isset($genreLookup[$genreKey])) {
        $selectedGenre = $genreLookup[$genreKey];
    } else {
        $selectedGenre = '';
    }
}
unset($genreLookup);

$yearInts = array_map('intval', $years);
$heatmapMinYear = !empty($yearInts) ? min($yearInts) : (int)date('Y');
$heatmapMaxYear = !empty($yearInts) ? max($yearInts) : $heatmapMinYear;
$heatmapFromYear = $heatmapMinYear;
$heatmapToYear = $heatmapMaxYear;
if (isset($_POST['heatmap_from'])) {
    $heatmapFromYear = clamp_int($_POST['heatmap_from'], $heatmapMinYear, $heatmapMaxYear, $heatmapFromYear);
}
if (isset($_POST['heatmap_to'])) {
    $heatmapToYear = clamp_int($_POST['heatmap_to'], $heatmapMinYear, $heatmapMaxYear, $heatmapToYear);
}
if ($heatmapFromYear > $heatmapToYear) {
    [$heatmapFromYear, $heatmapToYear] = [$heatmapToYear, $heatmapFromYear];
}
$heatmapGranularity = isset($_POST['heatmap_granularity']) ? strtolower((string)$_POST['heatmap_granularity']) : 'month';
if (!in_array($heatmapGranularity, ['month', 'year'], true)) {
    $heatmapGranularity = 'month';
}
$heatmapK = clamp_int($_POST['heatmap_k'] ?? $DEF_K, 1, 50, $DEF_K);
$heatmapRangeFrom = sprintf('%04d-01-01', $heatmapFromYear);
$heatmapRangeTo = sprintf('%04d-12-31', $heatmapToYear);

$heatmapPoints = [];
$heatmapMap = [];
$heatmapComputed = false;
$heatmapCacheStatus = null;
$heatmapQueryMs = null;
$heatmapMaxScoreValue = null;
$heatmapMinScoreValue = null;
$heatmapMaxScoreForColor = 1.0;
$heatmapConfigForJs = null;
$heatmapHasScores = false;

$heatmapYears = $heatmapFromYear <= $heatmapToYear ? range($heatmapFromYear, $heatmapToYear) : [];

$heatmapMonthNames = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'May',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Aug',
    9 => 'Sep',
    10 => 'Oct',
    11 => 'Nov',
    12 => 'Dec',
];

// -------------------------- Request handling --------------------------
$q         = '';
$limit     = $DEF_LIMIT;
$w_sim     = $DEF_W_SIM;
$w_topic   = $DEF_W_TOP;
$thresh    = $DEF_THRESH;
$k         = $DEF_K;
$results        = [];
$errorMsg       = '';
$rerankNotice   = '';
$timing         = ['embed_ms' => null, 'sql_ms' => null, 'cache' => 'miss'];
$rerankMode     = 'local';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $q = isset($_POST['q']) ? (string)$_POST['q'] : '';
    $limit = clamp_int($_POST['limit'] ?? $DEF_LIMIT, 1, 50, $DEF_LIMIT);
    $postedMode = isset($_POST['rerank_mode']) ? (string)$_POST['rerank_mode'] : 'local';
    if (in_array($postedMode, ['none', 'local', 'voyageai'], true)) {
        $rerankMode = $postedMode;
    } else {
        $rerankMode = 'local';
    }

    // Server-fixed knobs (hide UI for now; you can expose later)
    $w_sim   = $DEF_W_SIM;
    $w_topic = $DEF_W_TOP;
    $thresh  = $DEF_THRESH;
    $k       = $DEF_K;

    $q = trim($q);
    if ($q === '' || mb_strlen($q) > 512) {
        $errorMsg = 'Please enter a query (max 512 characters).';
    } elseif ($OPENAI_API_KEY === '') {
        $errorMsg = 'OPENAI_API_KEY is not configured.';
    } else {
        $pgconn = null;
        try {
            $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD, $PG_STMT_TIMEOUT);

            $qkey = normalize_query_key($q);
            $emb = cache_get($pdo, $qkey);
            if ($emb !== null) {
                $timing['cache'] = 'hit';
            } else {
                $t0 = microtime(true);
                $emb = openai_embed($OPENAI_API_KEY, $q, $OPENAI_MODEL, $OPENAI_TIMEOUT);
                $timing['embed_ms'] = (int)round((microtime(true) - $t0) * 1000);
                cache_put($pdo, $qkey, $emb);
            }

            $vec = vector_literal($emb);

            $pgconn = pg_connect_with_options($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD);
            pg_query($pgconn, 'SET enable_sort = off');
            //pg_query($pgconn, 'SET hnsw.ef_search = 80');

            // PHP params order (leave as-is):
            // [$vec, $w_sim, $w_topic, $thresh, $k, $limit, $pubname, $year, $genre]
            $sql_pg = <<<SQL
WITH params AS (
  SELECT
    $1::vector AS v,
    $2::float  AS w_sem,
    $3::float  AS w_topic,
    $4::float  AS thresh,
    $5::int    AS k
),
topic_candidates AS (
  SELECT tl.topic,
         1 - (tl.emb_hv <=> ($1::halfvec(3072))) AS sim
  FROM topic_labels AS tl
  WHERE tl.emb_hv IS NOT NULL
  ORDER BY tl.emb_hv <=> ($1::halfvec(3072))
  LIMIT (SELECT k FROM params)
),
inferred AS (
  SELECT topic, GREATEST(sim - (SELECT thresh FROM params), 0) AS weight
  FROM topic_candidates
  WHERE sim >= (SELECT thresh FROM params)
),
doc_candidates AS (
  SELECT d.id
  FROM docs d
  WHERE d.embedding_hv IS NOT NULL
    AND (d.meta->'topics' IS NULL OR jsonb_typeof(d.meta->'topics')='array')
    AND ($7::text IS NULL OR d.pubname = $7::text)
    AND ($8::int IS NULL OR EXTRACT(YEAR FROM d.date)::int = $8::int)
    AND ($9::text IS NULL OR EXISTS (
          SELECT 1
          FROM unnest(COALESCE(d.genre, ARRAY[]::text[])) AS g(val)
          WHERE lower(btrim(g.val)) = lower(btrim($9::text))
        ))
  ORDER BY d.embedding_hv <=> ($1::halfvec(3072))
  LIMIT GREATEST((SELECT k FROM params), $6 * 10)
),
scored AS (
  SELECT d.id, d.source_file, d.summary_clean,
         1 - (d.embedding <=> (SELECT v FROM params)) AS sim,

         -- raw topic signals (for display)
         COALESCE((
           SELECT AVG(i.weight)
           FROM inferred i
           JOIN LATERAL jsonb_array_elements_text(d.meta->'topics') x(topic) ON true
           WHERE lower(x.topic) = lower(i.topic)
         ), 0) AS topic_display,

         -- clipped for scoring (unchanged behavior)
         COALESCE(LEAST((
           SELECT SUM(i.weight)
           FROM inferred i
           JOIN LATERAL jsonb_array_elements_text(d.meta->'topics') x(topic) ON true
           WHERE lower(x.topic) = lower(i.topic)
         ), 1), 0) AS topic_boost
  FROM docs d
  JOIN doc_candidates dc ON dc.id = d.id
)
SELECT s.id,
       s.source_file,
       left(s.summary_clean,1500) AS snippet,
       s.sim,
       s.topic_display,
       s.topic_boost,
       (SELECT w_sem FROM params)*s.sim + (SELECT w_topic FROM params)*s.topic_boost AS final_score,
       d.meta->>'issue' AS issue,
       d.meta->>'title' AS title,
       d.meta->>'journal' AS journal,
       d.meta->>'anchor' AS anchor,
       d.meta->>'genre' AS genre,
       d.meta->>'topics' AS topics,
       pubname AS pubname,
       date as date,
       (d.meta->>'first_page')::int AS first_page,
       p.page_id,
       hc.context AS hcontext
FROM scored s
JOIN docs d ON d.id = s.id
LEFT JOIN LATERAL (
  SELECT context
  FROM hcontext h
  WHERE h.fid = d.id
  ORDER BY h.id DESC
  LIMIT 1
) hc ON true
LEFT JOIN pages p ON p.issue = d.issue AND p.page = d.page
ORDER BY final_score DESC
LIMIT $6;
SQL;

            $t0 = microtime(true);
            $pubnameParam = ($selectedPub === '') ? null : $selectedPub;
            $yearParam = ($selectedYear === '') ? null : $selectedYear;
            $genreParam = ($selectedGenre === '') ? null : $selectedGenre;
            $params = [$vec, $w_sim, $w_topic, $thresh, $k, $limit, $pubnameParam, $yearParam, $genreParam];
            $res = pg_query_params($pgconn, $sql_pg, $params);



            if ($res === false) {
                throw new RuntimeException('pg_query_params failed: ' . pg_last_error($pgconn));
            }
            $results = pg_fetch_all($res) ?: [];
            $timing['sql_ms'] = (int)round((microtime(true) - $t0) * 1000);

            $heatmapCacheKey = heatmap_cache_key($qkey, $heatmapRangeFrom, $heatmapRangeTo, $heatmapGranularity, $heatmapK, $OPENAI_MODEL);
            $cachedHeatmap = cache_get($pdo, $heatmapCacheKey);
            if (is_array($cachedHeatmap) && isset($cachedHeatmap['points']) && is_array($cachedHeatmap['points'])) {
                $heatmapPoints = $cachedHeatmap['points'];
                $heatmapCacheStatus = 'hit';
            } else {
                $heatmapCacheStatus = 'miss';
                $tHeatmap = microtime(true);
                $heatmapPoints = run_heatmap_query($pgconn, $vec, $heatmapRangeFrom, $heatmapRangeTo, $heatmapGranularity, $heatmapK);
                $heatmapQueryMs = (int)round((microtime(true) - $tHeatmap) * 1000);
                $cachePayload = [
                    'points' => $heatmapPoints,
                    'from' => $heatmapRangeFrom,
                    'to' => $heatmapRangeTo,
                    'granularity' => $heatmapGranularity,
                    'k' => $heatmapK,
                    'model' => $OPENAI_MODEL,
                ];
                cache_put($pdo, $heatmapCacheKey, $cachePayload);
            }
            $heatmapComputed = true;
            $heatmapConfigForJs = [
                'query' => $q,
                'granularity' => $heatmapGranularity,
                'range_from' => $heatmapRangeFrom,
                'range_to' => $heatmapRangeTo,
                'k' => $heatmapK,
                'model' => $OPENAI_MODEL,
            ];




        } catch (Throwable $e) {
            $errorMsg = 'Search failed. See server logs.';
            error_log('[semantic_search] ' . $e->getMessage());
        } finally {
            if ($pgconn) {
                pg_close($pgconn);
            }
        }
    }
}


if ($heatmapComputed) {
    foreach ($heatmapPoints as $point) {
        if (!is_array($point) || !isset($point['period_key'])) {
            continue;
        }
        $key = (string)$point['period_key'];
        $score = isset($point['score']) ? (float)$point['score'] : null;
        $kCount = isset($point['k_count']) ? (int)$point['k_count'] : 0;
        $heatmapMap[$key] = [
            'score' => $score,
            'k_count' => $kCount,
        ];
        if ($score !== null) {
            $heatmapHasScores = true;
            $heatmapMaxScoreValue = $heatmapMaxScoreValue === null ? $score : max($heatmapMaxScoreValue, $score);
            $heatmapMinScoreValue = $heatmapMinScoreValue === null ? $score : min($heatmapMinScoreValue, $score);
        }
    }

    if ($heatmapMaxScoreValue !== null && $heatmapMaxScoreValue > 0) {
        $heatmapMaxScoreForColor = $heatmapMaxScoreValue;
    } else {
        $heatmapMaxScoreForColor = 1.0;
    }
}


function english_title_case($str)
{
    // Words that should stay lowercase unless first/last
    $smallWords = [
        'a','an','and','as','at','but','by','for','in','nor',
        'of','on','or','per','the','to','vs','via'
    ];

    $words = preg_split('/\s+/', strtolower($str));
    $lastIndex = count($words) - 1;

    foreach ($words as $i => &$word) {
        if ($i === 0 || $i === $lastIndex || !in_array($word, $smallWords)) {
            $word = ucfirst($word);
        }
    }

    return implode(' ', $words);
}



$anchors_raw = $row['anchor'] ?? '';
$anchors = is_array($anchors_raw) ? $anchors_raw : (json_decode($anchors_raw, true) ?: []);

// 2) Make a simple CSV string (M.P.,Lloyd’s,Lord John Russell,Jeremy Taylor)
$csv = implode(',', array_map('trim', $anchors));



$venvPy = '/srv/http/calibre-nilla/reranker/.venv/bin/python';
$cli    = '/srv/http/calibre-nilla/reranker/rerank_cli.py';


if (!empty($results)) {
    $effectiveRerankMode = $rerankMode;

    if ($rerankMode === 'voyageai' && $VOYAGE_API_KEY === '') {
        $rerankNotice = 'Voyage reranker selected but VOYAGE_API_KEY is not configured. Showing original order.';
        $effectiveRerankMode = 'none';
    }

    if ($effectiveRerankMode !== 'none') {
        foreach ($results as $i => &$row) {
            $row['_orig_index'] = $i;
        }
        unset($row);

        try {
            $docTexts = [];
            foreach ($results as $idx => $row) {
                $docTexts[$idx] = mb_substr(($row['title'] ?? '') . ' — ' . ($row['snippet'] ?? ''), 0, 1200);
            }

            switch ($effectiveRerankMode) {
                case 'local':
                    $docs = [];
                    foreach ($results as $idx => $row) {
                        $docs[] = [
                            'id'   => (string)($row['id'] ?? $idx),
                            'text' => $docTexts[$idx] ?? '',
                        ];
                    }

                    $payload = json_encode(['query' => $q, 'documents' => $docs], JSON_UNESCAPED_UNICODE);

                    $descs = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
                    $env = ['OMP_NUM_THREADS' => '1', 'MKL_NUM_THREADS' => '1'];
                    $proc = proc_open($venvPy . ' ' . escapeshellarg($cli), $descs, $pipes, null, $env);
                    if (!is_resource($proc)) {
                        throw new RuntimeException('Failed to start local reranker process.');
                    }

                    fwrite($pipes[0], $payload);
                    fclose($pipes[0]);
                    $out = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    $err = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                    proc_close($proc);

                    $j = json_decode($out, true);
                    if (!is_array($j) || !isset($j['scores']) || !is_array($j['scores'])) {
                        throw new RuntimeException('Local reranker returned invalid response: ' . ($err ?: 'no stderr output'));
                    }

                    $scores = [];
                    foreach ($j['scores'] as $s) {
                        if (!isset($s['id']) || !isset($s['score'])) {
                            continue;
                        }
                        $scores[(string)$s['id']] = (float)$s['score'];
                    }

                    usort($results, function ($a, $b) use ($scores) {
                        $sa = $scores[(string)($a['id'] ?? '')] ?? -INF;
                        $sb = $scores[(string)($b['id'] ?? '')] ?? -INF;
                        if ($sa == $sb) {
                            return ((float)($b['final_score'] ?? 0)) <=> ((float)($a['final_score'] ?? 0));
                        }
                        return $sb <=> $sa;
                    });
                    break;

                case 'voyageai':
                    $voyageResults = voyage_rerank($VOYAGE_API_KEY, $q, array_values($docTexts), $VOYAGE_RERANK_MODEL, $VOYAGE_TIMEOUT);

                    $scoresByIndex = [];
                    foreach ($voyageResults as $item) {
                        $idx = null;
                        if (isset($item['index'])) {
                            $idx = (int)$item['index'];
                        } elseif (isset($item['document']['index'])) {
                            $idx = (int)$item['document']['index'];
                        }

                        if ($idx === null) {
                            continue;
                        }

                        $score = $item['relevance_score'] ?? $item['score'] ?? $item['relevanceScore'] ?? null;
                        if ($score === null) {
                            continue;
                        }

                        $scoresByIndex[$idx] = (float)$score;
                    }

                    usort($results, function ($a, $b) use ($scoresByIndex) {
                        $ia = (int)($a['_orig_index'] ?? -1);
                        $ib = (int)($b['_orig_index'] ?? -1);
                        $sa = $scoresByIndex[$ia] ?? -INF;
                        $sb = $scoresByIndex[$ib] ?? -INF;
                        if ($sa == $sb) {
                            return ((float)($b['final_score'] ?? 0)) <=> ((float)($a['final_score'] ?? 0));
                        }
                        return $sb <=> $sa;
                    });
                    break;
            }
        } catch (Throwable $e) {
            if ($rerankNotice === '') {
                $modeLabel = $effectiveRerankMode === 'voyageai' ? 'Voyage reranker' : 'Local reranker';
                $rerankNotice = $modeLabel . ' failed; showing original order.';
            }
            error_log('[semantic_search] rerank (' . $effectiveRerankMode . '): ' . $e->getMessage());
        }

        foreach ($results as &$row) {
            unset($row['_orig_index']);
        }
        unset($row);
    }
}

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Semantic Search</title>

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome 6 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <style>

    .score-badge { font-variant-numeric: tabular-nums; }
    .snippet { white-space: pre-wrap; }
    .heatmap-card { background-color: #f8f9fa; border: 1px solid rgba(0, 0, 0, 0.05); }
    .heatmap-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-variant-numeric: tabular-nums; font-size: 0.8rem; }
    .heatmap-table th,
    .heatmap-table td { border: 1px solid rgba(0, 0, 0, 0.05); text-align: center; padding: 0.35rem; }
    .heatmap-table th { background-color: #f1f5f9; font-weight: 600; }
    .heatmap-cell { cursor: pointer; transition: transform 0.1s ease-in-out; }
    .heatmap-cell.has-score:hover { transform: scale(1.03); }
    .heatmap-cell.is-empty { cursor: not-allowed; color: #6c757d; background-color: #f8f9fa; }
    .heatmap-legend { height: 0.75rem; border-radius: 999px; background: linear-gradient(to right, #e3ecff, #0d6efd); }
    .heatmap-legend-labels { display: flex; justify-content: space-between; font-size: 0.75rem; color: #6c757d; }
    .heatmap-meta { font-size: 0.8rem; color: #6c757d; }
    .heatmap-config-badge { font-size: 0.75rem; }
  </style>
</head>
<body>

<header class="position-relative w-100 pb-4" 
        style="background: url('images/backfrop.jpg') center center / cover no-repeat; height: 400px;">
  <div class="position-absolute top-0 start-0 w-100 h-100" style="background: rgba(0,0,0,0.4);"></div>
  <div class="container h-100 d-flex flex-column align-items-center justify-content-center position-relative text-center">
    <h1 class="text-white display-3 fw-bold">Nillas archive</h1>
    <p class="text-white fs-4 fst-italic">News from the Victorian Era</p>
  </div>
</header>

<div class="container">
<form method="post" class="mb-4 mt-4" action="">
  <div class="d-flex flex-wrap align-items-end gap-2">
    <!-- Query -->
    <div class="flex-grow-1">
      <label for="q" class="form-label">Query</label>
      <input
        type="text"
        class="form-control"
        id="q"
        name="q"
        maxlength="512"
        required
        value="<?= h($q) ?>"
        placeholder="Type your search query...">
    </div>

    <!-- Publication -->
    <div>
      <label for="pubname" class="form-label">Publication</label>
      <select name="pubname" id="pubname" class="form-select">
        <option value="" <?= $selectedPub === '' ? 'selected' : '' ?>>
          All (<?= number_format($total) ?>)
        </option>
        <?php foreach ($pubnames as $p): ?>
          <?php $cnt = $counts[$p] ?? 0; ?>
          <option value="<?= h($p) ?>" <?= strcasecmp($selectedPub, $p) === 0 ? 'selected' : '' ?>>
            <?= h($p) ?> (<?= number_format($cnt) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Year -->
    <div>
      <label for="year" class="form-label">Year</label>
      <select name="year" id="year" class="form-select">
        <option value="" <?= $selectedYear === '' ? 'selected' : '' ?>>
          All Years<?php if ($yearTotal > 0): ?> (<?= number_format($yearTotal) ?>)<?php endif; ?>
        </option>
        <?php foreach ($years as $year): ?>
          <?php $yearCnt = $yearCounts[$year] ?? 0; ?>
          <option value="<?= h($year) ?>" <?= $selectedYear === $year ? 'selected' : '' ?>>
            <?= h($year) ?> (<?= number_format($yearCnt) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Genre -->
    <div>
      <label for="genre" class="form-label">Genre</label>
      <select name="genre" id="genre" class="form-select">
        <option value="" <?= $selectedGenre === '' ? 'selected' : '' ?>>All Genres</option>
        <?php foreach ($genres as $genre): ?>
          <?php $genreCnt = $genreCounts[$genre] ?? 0; ?>
          <option value="<?= h($genre) ?>" <?= $selectedGenre === $genre ? 'selected' : '' ?>>
            <?= h($genre) ?> (<?= number_format($genreCnt) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Results -->
    <div>
      <label for="limit" class="form-label">Results</label>
      <input
        type="number"
        min="1"
        max="50"
        class="form-control"
        id="limit"
        name="limit"
        value="<?= h((string)$limit) ?>">
    </div>

    <!-- Reranker -->
    <div>
      <label for="rerank_mode" class="form-label">Reranker</label>
      <select class="form-select" id="rerank_mode" name="rerank_mode">
        <option value="voyageai" <?= $rerankMode === 'voyageai' ? 'selected' : '' ?>>Voyage AI (rerank-2.5)</option>
        <option value="local" <?= $rerankMode === 'local' ? 'selected' : '' ?>>Local reranker</option>
        <option value="none" <?= $rerankMode === 'none' ? 'selected' : '' ?>>None</option>
      </select>
    </div>

    <!-- Submit -->
    <div class="d-grid">
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-search me-1" aria-hidden="true"></i>
        Search
      </button>
    </div>
  </div>

  <div class="bg-light-subtle border rounded-3 p-3 mt-3">
    <div class="row g-3 align-items-end">
      <div class="col-6 col-md-3">
        <label for="heatmap_from" class="form-label">Heatmap from (year)</label>
        <input
          type="number"
          class="form-control"
          id="heatmap_from"
          name="heatmap_from"
          min="<?= h((string)$heatmapMinYear) ?>"
          max="<?= h((string)$heatmapMaxYear) ?>"
          value="<?= h((string)$heatmapFromYear) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label for="heatmap_to" class="form-label">Heatmap to (year)</label>
        <input
          type="number"
          class="form-control"
          id="heatmap_to"
          name="heatmap_to"
          min="<?= h((string)$heatmapMinYear) ?>"
          max="<?= h((string)$heatmapMaxYear) ?>"
          value="<?= h((string)$heatmapToYear) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label for="heatmap_granularity" class="form-label">Granularity</label>
        <select class="form-select" id="heatmap_granularity" name="heatmap_granularity">
          <option value="month" <?= $heatmapGranularity === 'month' ? 'selected' : '' ?>>Monthly</option>
          <option value="year" <?= $heatmapGranularity === 'year' ? 'selected' : '' ?>>Yearly</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label for="heatmap_k" class="form-label">Top-k per bucket</label>
        <input
          type="number"
          class="form-control"
          id="heatmap_k"
          name="heatmap_k"
          min="1"
          max="50"
          value="<?= h((string)$heatmapK) ?>">
      </div>
    </div>
    <div class="form-text mt-2">
      Heatmap scores use the mean cosine similarity of the top <?= h((string)$heatmapK) ?> document embeddings per
      <?= h($heatmapGranularity === 'year' ? 'year' : 'month') ?> within <?= h($heatmapRangeFrom) ?> to <?= h($heatmapRangeTo) ?>.
    </div>
  </div>

  <div class="form-text mt-2 d-flex align-items-center flex-wrap gap-2">
    <span>
      Using inferred topics only. Weights:
      w<sub>sim</sub>=<?=$w_sim?>, w<sub>topic</sub>=<?=$w_topic?>,
      thresh=<?=$thresh?>, k=<?=$k?>.
    </span>
    <?php if ($timing['embed_ms'] !== null || $timing['sql_ms'] !== null): ?>
      <span class="badge text-bg-secondary">
        <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>cache: <?=$timing['cache']?>
      </span>
      <?php if ($timing['embed_ms'] !== null): ?>
        <span class="badge text-bg-secondary">
          <i class="fa-solid fa-microchip me-1" aria-hidden="true"></i>embed: <?=$timing['embed_ms']?>ms
        </span>
      <?php endif; ?>
      <?php if ($timing['sql_ms'] !== null): ?>
        <span class="badge text-bg-secondary">
          <i class="fa-solid fa-database me-1" aria-hidden="true"></i>sql: <?=$timing['sql_ms']?>ms
        </span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</form>

<?php
$heatmapConfigJson = $heatmapConfigForJs !== null ? h(json_encode($heatmapConfigForJs, JSON_UNESCAPED_SLASHES)) : '';
?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errorMsg): ?>
  <section class="card heatmap-card mb-4" id="heatmap-root" <?= $heatmapConfigJson !== '' ? 'data-config="' . $heatmapConfigJson . '"' : '' ?>>
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">
          <i class="fa-solid fa-chart-area me-2"></i>
          Query relevance heatmap
        </h2>
        <div class="d-flex flex-wrap gap-2 heatmap-meta">
          <span class="badge text-bg-light text-dark heatmap-config-badge">Range: <?= h($heatmapRangeFrom) ?> → <?= h($heatmapRangeTo) ?></span>
          <span class="badge text-bg-light text-dark heatmap-config-badge">Granularity: <?= h(ucfirst($heatmapGranularity)) ?></span>
          <span class="badge text-bg-light text-dark heatmap-config-badge">Top-k: <?= h((string)$heatmapK) ?></span>
          <?php if ($heatmapCacheStatus): ?>
            <span class="badge text-bg-light text-dark heatmap-config-badge">Heatmap cache: <?= h($heatmapCacheStatus) ?></span>
          <?php endif; ?>
          <?php if ($heatmapQueryMs !== null): ?>
            <span class="badge text-bg-light text-dark heatmap-config-badge">Heatmap SQL: <?= h((string)$heatmapQueryMs) ?>ms</span>
          <?php endif; ?>
        </div>
      </div>
      <p class="text-body-secondary small mb-3">
        Scores reflect the mean cosine similarity of the top <?= h((string)$heatmapK) ?> matching documents per
        <?= $heatmapGranularity === 'year' ? 'year' : 'month' ?>. Click a cell to preview the top articles for that period.
      </p>

      <?php if ($heatmapComputed && $heatmapConfigForJs !== null): ?>
        <?php if ($heatmapGranularity === 'month'): ?>
          <?php if (!empty($heatmapYears)): ?>
            <div class="table-responsive">
              <table class="heatmap-table">
                <thead>
                  <tr>
                    <th scope="col">Year</th>
                    <?php foreach ($heatmapMonthNames as $monthNum => $monthLabel): ?>
                      <th scope="col"><?= h($monthLabel) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($heatmapYears as $year): ?>
                    <tr>
                      <th scope="row"><?= h((string)$year) ?></th>
                      <?php for ($month = 1; $month <= 12; $month++):
                          $periodKey = sprintf('%04d-%02d', $year, $month);
                          $cell = $heatmapMap[$periodKey] ?? null;
                          $score = $cell['score'] ?? null;
                          $kCount = $cell['k_count'] ?? 0;
                          $hasScore = $score !== null;
                          $cellClass = 'heatmap-cell' . ($hasScore ? ' has-score' : ' is-empty');
                          $label = ($heatmapMonthNames[$month] ?? $month) . ' ' . $year;
                          $ratio = $hasScore && $heatmapMaxScoreForColor > 0 ? max(0.0, min(1.0, $score / $heatmapMaxScoreForColor)) : 0.0;
                          [$bgColor, $useLightText] = heatmap_color_for_ratio($ratio);
                          $textClass = $useLightText ? 'text-white fw-semibold' : '';
                          $displayScore = $hasScore ? number_format($score, 2, '.', '') : '';
                          $periodStart = sprintf('%04d-%02d-01', $year, $month);
                          $periodEndObj = DateTimeImmutable::createFromFormat('Y-m-d', $periodStart);
                          $periodEnd = $periodEndObj ? $periodEndObj->format('Y-m-t') : $periodStart;
                          $titleParts = [];
                          if ($hasScore) {
                              $titleParts[] = 'Score ' . number_format($score, 3, '.', '');
                          }
                          $titleParts[] = 'Docs ' . $kCount;
                          $titleParts[] = 'Click for top articles';
                          $title = implode(' • ', $titleParts);
                      ?>
                        <td class="<?= $cellClass ?> <?= $textClass ?>"
                            style="background-color: <?= h($bgColor) ?>;"
                            <?= $hasScore ? 'data-period-key="' . h($periodKey) . '" data-label="' . h($label) . '" data-score="' . h(number_format($score, 4, '.', '')) . '" data-k-count="' . h((string)$kCount) . '" data-period-start="' . h($periodStart) . '" data-period-end="' . h($periodEnd) . '"' : '' ?>
                            title="<?= h($title) ?>">
                          <?= $hasScore ? h($displayScore) : '&ndash;' ?>
                        </td>
                      <?php endfor; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="alert alert-info mb-0">No years available for the selected range.</div>
          <?php endif; ?>
        <?php else: ?>
          <?php if (!empty($heatmapYears)): ?>
            <div class="table-responsive">
              <table class="heatmap-table">
                <thead>
                  <tr>
                    <th scope="col">Metric</th>
                    <?php foreach ($heatmapYears as $year): ?>
                      <th scope="col"><?= h((string)$year) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <th scope="row">Score</th>
                    <?php foreach ($heatmapYears as $year):
                        $periodKey = sprintf('%04d', $year);
                        $cell = $heatmapMap[$periodKey] ?? null;
                        $score = $cell['score'] ?? null;
                        $kCount = $cell['k_count'] ?? 0;
                        $hasScore = $score !== null;
                        $cellClass = 'heatmap-cell' . ($hasScore ? ' has-score' : ' is-empty');
                        $ratio = $hasScore && $heatmapMaxScoreForColor > 0 ? max(0.0, min(1.0, $score / $heatmapMaxScoreForColor)) : 0.0;
                        [$bgColor, $useLightText] = heatmap_color_for_ratio($ratio);
                        $textClass = $useLightText ? 'text-white fw-semibold' : '';
                        $displayScore = $hasScore ? number_format($score, 2, '.', '') : '';
                        $periodStart = sprintf('%04d-01-01', $year);
                        $periodEnd = sprintf('%04d-12-31', $year);
                        $titleParts = [];
                        if ($hasScore) {
                            $titleParts[] = 'Score ' . number_format($score, 3, '.', '');
                        }
                        $titleParts[] = 'Docs ' . $kCount;
                        $titleParts[] = 'Click for top articles';
                        $title = implode(' • ', $titleParts);
                    ?>
                      <td class="<?= $cellClass ?> <?= $textClass ?>"
                          style="background-color: <?= h($bgColor) ?>;"
                          <?= $hasScore ? 'data-period-key="' . h($periodKey) . '" data-label="' . h((string)$year) . '" data-score="' . h(number_format($score, 4, '.', '')) . '" data-k-count="' . h((string)$kCount) . '" data-period-start="' . h($periodStart) . '" data-period-end="' . h($periodEnd) . '"' : '' ?>
                          title="<?= h($title) ?>">
                        <?= $hasScore ? h($displayScore) : '&ndash;' ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="alert alert-info mb-0">No years available for the selected range.</div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($heatmapHasScores): ?>
          <div class="mt-3">
            <div class="heatmap-legend"></div>
            <div class="heatmap-legend-labels">
              <span><?= h(number_format($heatmapMinScoreValue ?? 0, 2, '.', '')) ?></span>
              <span><?= h(number_format($heatmapMaxScoreValue ?? 0, 2, '.', '')) ?></span>
            </div>
          </div>
        <?php endif; ?>
      <?php elseif ($heatmapComputed): ?>
        <div class="alert alert-warning mb-0">Heatmap data is unavailable for this query.</div>
      <?php else: ?>
        <div class="alert alert-info mb-0">Heatmap has not been generated for this query yet.</div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

    <?php if ($errorMsg): ?>
      <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i><?=h($errorMsg)?>
      </div>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errorMsg && $rerankNotice): ?>
      <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i><?=h($rerankNotice)?>
      </div>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errorMsg): ?>
      <?php if (!$results): ?>
        <div class="alert alert-warning" role="alert">
          <i class="fa-regular fa-face-frown me-1" aria-hidden="true"></i>No results (maybe no embeddings yet).
        </div>
      <?php else: ?>
        <ol class="list-group list-group-numbered">
          <?php foreach ($results as $row):
              $anchors_raw = $row['anchor'] ?? '';
              $anchors = is_array($anchors_raw) ? $anchors_raw : (json_decode($anchors_raw, true) ?: []);

              $genres_raw  = $row['genre']  ?? '[]';
              $topics_raw  = $row['topics'] ?? '[]';

              $genres = is_array($genres_raw) ? $genres_raw : (json_decode($genres_raw, true) ?: []);
              $topics = is_array($topics_raw) ? $topics_raw : (json_decode($topics_raw, true) ?: []);

              $historicalContext = trim((string)($row['hcontext'] ?? ''));
              $docTitle = english_title_case($row['title'] ?? '');

              // 2) Make a simple CSV string (M.P.,Lloyd’s,Lord John Russell,Jeremy Taylor)
              $csv = implode(',', array_map('trim', $anchors));


              // 3) Build your link with proper encoding of the query params
              $pageId = $row['page_id'] ?? null;
              $hasPageId = $pageId !== null && $pageId !== '';
              if ($hasPageId) {
                  $pageLinkParams = [
                      'page_id' => (string)$pageId,
                      'q'       => $csv,
                  ];
                  $journalParam = $row['journal'] ?? null;
                  if (is_string($journalParam) && $journalParam !== '') {
                      $pageLinkParams['journal'] = $journalParam;
                  }
                  $hrefILN = '/semantic/page_json.php?' . http_build_query($pageLinkParams);
              } else {
                  $hrefILN = null;
              }

              $thumbLinkAttrs = $hasPageId
                  ? ' target="_blank" rel="noopener"'
                  : ' aria-disabled="true"';
              $sourceBtnClasses = 'btn btn-outline-secondary' . ($hasPageId ? '' : ' disabled');
              $sourceBtnAttrs = $hasPageId
                  ? ' target="_blank" rel="noopener"'
                  : ' aria-disabled="true" tabindex="-1"';

              ?>

<li class="list-group-item">
  <div class="d-flex justify-content-between align-items-start gap-3">

    <!-- Left: thumbnail -->
    <?php $page_num = str_pad((string)($row['first_page'] ?? ''), 4, '0', STR_PAD_LEFT); ?>
    <a<?= $thumbLinkAttrs ?> href="<?= $hrefILN ? h($hrefILN) : '#' ?>">
    <img src="/<?= rawurlencode($row['journal'] ?? '') ?>/<?= rawurlencode($row['issue'] ?? '') ?>/pages/page-<?= rawurlencode((string)$page_num) ?>_thumb.webp"
     alt="Page <?= h($page_num) ?>"
     style="width:150px; height:auto;" />

    <!-- Middle: main text -->
    <div class="flex-grow-1">
      <div class="h5 mt-2 mb-1">
        <?=h($row['pubname'] ?? '')?> <?=h($row['date'] ?? '')?> - Page: <?=h($row['first_page'] ?? '') ?>
        <div class="btn-group btn-group-sm ms-2" role="group" aria-label="Result links">
          <a class="<?= h($sourceBtnClasses) ?>"<?= $sourceBtnAttrs ?> href="<?= $hrefILN ? h($hrefILN) : '#' ?>">
            <i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i>Source
          </a>
          <button type="button"
                  class="btn btn-outline-secondary js-historical-context"
                  data-doc-id="<?= h((string)($row['id'] ?? '')) ?>"
                  data-doc-title="<?= h($docTitle) ?>">
            <i class="fa-solid fa-landmark me-1" aria-hidden="true"></i>Historical Context
          </button>
          <a class="btn btn-outline-secondary js-view-md" target="_blank" rel="noopener"
             href="/EWJ_issues/<?=h($row['issue'] ?? '')?>.md">
            <i class="fa-regular fa-file-lines me-1" aria-hidden="true"></i>Full Summary
          </a>
        </div>
        <span class="text-body-secondary small ms-2">Doc ID: <?=h((string)$row['id'])?></span>
        <?php if ($hasPageId): ?>
          <span class="text-body-secondary small ms-2">Page ID: <?=h((string)$pageId)?></span>
        <?php endif; ?>
      </div>

      <h2 class="h5 mt-2 mb-1"><?=h($docTitle);?></h2>
      <div class="snippet"><?=h($row['snippet'] ?? '')?></div>

      <?php $hasHistoricalContext = $historicalContext !== ''; ?>
      <div class="mt-3 js-historical-context-area">
        <div class="js-historical-context-wrapper<?= $hasHistoricalContext ? '' : ' d-none' ?>"<?= $hasHistoricalContext ? '' : ' hidden' ?>>
          <h3 class="h6 mb-1">
            <i class="fa-solid fa-landmark me-1" aria-hidden="true"></i>
            Historical Context
            <span class="js-historical-context-title text-body-secondary small ms-2">
              <?php if ($hasHistoricalContext && $docTitle !== ''): ?>
                <?=h($docTitle)?>
              <?php endif; ?>
            </span>
          </h3>
          <div class="text-body-secondary small js-historical-context-content">
            <?php if ($hasHistoricalContext): ?>
              <?=nl2br(h($historicalContext), false)?>
            <?php endif; ?>
          </div>
        </div>
        <div class="js-historical-context-loading d-none text-body-secondary small d-flex align-items-center gap-2">
          <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
          <span>Contacting Claude…</span>
        </div>
        <div class="js-historical-context-error alert alert-danger d-none mt-2" role="alert"></div>
      </div>

      <!-- Genre and topics -->
      <div class="text-body-secondary small ms-2 mt-2">
        Genre: <?=h(implode(', ', $genres))?>
      </div>
      <div class="text-body-secondary small ms-2 mt-1">
        Topics: <?=h(implode(', ', $topics))?>
      </div>
    </div>

    <!-- Right: aligned badges -->
    <div class="text-end" style="min-width: 140px;">
      <div class="mb-1">
        <span class="badge text-bg-primary score-badge">
          <i class="fa-solid fa-star me-1" aria-hidden="true"></i>
          final <?=number_format((float)$row['final_score'], 4)?>
        </span>
      </div>
      <div class="mb-1">
        <span class="badge text-bg-light text-dark score-badge">
          <i class="fa-solid fa-wave-square me-1" aria-hidden="true"></i>
          sim <?=number_format((float)$row['sim'], 4)?>
        </span>
      </div>
      <div>
        <span class="badge text-bg-light text-dark score-badge">
          <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>
          topic <?= number_format((float)($row['topic_display'] ?? $row['topic_boost'] ?? 0), 3) ?>
        </span>
      </div>
    </div>

  </div>
</li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    <?php endif; ?>

    <hr class="my-4" />
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <div class="modal fade" id="heatmapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fa-solid fa-chart-simple me-2" aria-hidden="true"></i>
            <span class="js-heatmap-modal-title">Heatmap bucket</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="js-heatmap-modal-loading d-flex align-items-center gap-2 text-body-secondary small mb-3" hidden>
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
            <span>Loading…</span>
          </div>
          <div class="js-heatmap-modal-error alert alert-danger d-none" role="alert"></div>
          <ol class="js-heatmap-modal-list list-group list-group-numbered d-none"></ol>
          <div class="js-heatmap-modal-empty text-body-secondary small d-none">No matching articles were found for this period.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Markdown Modal -->
  <div class="modal fade" id="mdModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-regular fa-file-lines me-2"></i><span id="mdModalTitle">Full Summary</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="mdLoading" class="d-flex align-items-center gap-2 mb-3" hidden>
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Loading…</span>
          </div>
          <div id="mdError" class="alert alert-danger d-none" role="alert"></div>
          <article id="mdContent" class="markdown-body"></article>
        </div>
        <div class="modal-footer">
          <a id="mdOpenRaw" href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary">
            <i class="fa-solid fa-up-right-from-square me-1"></i>Open raw Markdown
          </a>
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <footer class="bg-light text-center text-muted py-3 mt-5">
  <div class="container">
    <small>
      <i class="fa-brands fa-creative-commons"></i>
      <i class="fa-brands fa-creative-commons-zero"></i>
      Text &amp; summaries created for this project are dedicated to the public domain under 
      <a href="https://creativecommons.org/publicdomain/zero/1.0/" target="_blank" class="text-decoration-none">CC0</a>. 
      Images sourced from the <a href="https://archive.org/" target="_blank" class="text-decoration-none">Internet Archive</a>; rights may apply.
    </small>
  </div>
</footer>


  <!-- Marked.js and DOMPurify for safe client-side rendering -->
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>



  <script>
(function() {
    document.addEventListener('click', function(evt) {
        const btn = evt.target.closest('button.js-historical-context');
        if (!btn) {
            return;
        }

        evt.preventDefault();

        const docId = btn.getAttribute('data-doc-id');
        if (!docId) {
            return;
        }

        const listItem = btn.closest('.list-group-item');
        if (!listItem) {
            return;
        }

        const wrapper = listItem.querySelector('.js-historical-context-wrapper');
        const contentEl = listItem.querySelector('.js-historical-context-content');
        const loadingEl = listItem.querySelector('.js-historical-context-loading');
        const errorEl = listItem.querySelector('.js-historical-context-error');
        const titleEl = listItem.querySelector('.js-historical-context-title');

        if (!contentEl) {
            return;
        }

        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('d-none');
        }
        if (loadingEl) {
            loadingEl.classList.remove('d-none');
        }

        const previousDisabled = btn.disabled;
        btn.disabled = true;

        fetch('historical_context.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    doc_id: docId
                })
            })
            .then(res => {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(data => {
                if (!data || data.ok !== true) {
                    const errMsg = data && data.error ? data.error : 'Unexpected response from server.';
                    throw new Error(errMsg);
                }
                const content = typeof data.content === 'string' ? data.content : '';
                const html = marked.parse(content, {
                    mangle: false,
                    headerIds: true,
                    breaks: true
                });
                contentEl.innerHTML = DOMPurify.sanitize(html);
                if (wrapper) {
                    wrapper.classList.remove('d-none');
                    wrapper.removeAttribute('hidden');
                }
                if (titleEl) {
                    const docTitle = btn.getAttribute('data-doc-title') || 'Historical Context';
                    titleEl.textContent = docTitle;
                }
            })
            .catch(err => {
                if (errorEl) {
                    errorEl.textContent = 'Failed to load historical context: ' + err.message;
                    errorEl.classList.remove('d-none');
                }
            })
            .finally(() => {
                if (loadingEl) {
                    loadingEl.classList.add('d-none');
                }
                btn.disabled = previousDisabled;
            });
    });
})();

(function() {
    const modalEl = document.getElementById('mdModal');
    const modal = new bootstrap.Modal(modalEl);
    const contentEl = document.getElementById('mdContent');
    const titleEl = document.getElementById('mdModalTitle');
    const loadingEl = document.getElementById('mdLoading');
    const errorEl = document.getElementById('mdError');
    const rawLinkEl = document.getElementById('mdOpenRaw');


    // Delegated click: open .js-view-md links in modal
    document.addEventListener('click', function(evt) {
        const link = evt.target.closest('a.js-view-md');
        if (!link) return;
        evt.preventDefault();


        const url = link.getAttribute('href');
        const fileName = url.split('/').pop();
        titleEl.textContent = fileName || 'Full Summary';
        rawLinkEl.href = url;


        // reset UI state
        contentEl.innerHTML = '';
        errorEl.classList.add('d-none');
        loadingEl.classList.remove('d-none');


        modal.show();


        fetch(url, {
                cache: 'no-store'
            })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(md => {
                const html = marked.parse(md, {
                    mangle: false,
                    headerIds: true,
                    breaks: true
                });
                contentEl.innerHTML = DOMPurify.sanitize(html);
            })
            .catch(err => {
                errorEl.textContent = 'Failed to load Markdown: ' + err.message;
                errorEl.classList.remove('d-none');
            })
            .finally(() => {
                loadingEl.classList.add('d-none');
            });
    });
})();

(function() {
    const root = document.getElementById('heatmap-root');
    if (!root) {
        return;
    }

    let config = null;
    const attr = root.getAttribute('data-config');
    if (attr) {
        try {
            config = JSON.parse(attr);
        } catch (err) {
            config = null;
        }
    }
    if (!config || !config.query) {
        return;
    }

    const modalEl = document.getElementById('heatmapModal');
    if (!modalEl) {
        return;
    }

    const modal = new bootstrap.Modal(modalEl);
    const titleEl = modalEl.querySelector('.js-heatmap-modal-title');
    const listEl = modalEl.querySelector('.js-heatmap-modal-list');
    const loadingEl = modalEl.querySelector('.js-heatmap-modal-loading');
    const errorEl = modalEl.querySelector('.js-heatmap-modal-error');
    const emptyEl = modalEl.querySelector('.js-heatmap-modal-empty');

    const escapeHtml = (value) => {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const showLoading = () => {
        if (loadingEl) {
            loadingEl.hidden = false;
            loadingEl.classList.remove('d-none');
        }
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.classList.add('d-none');
        }
        if (emptyEl) {
            emptyEl.classList.add('d-none');
        }
        if (listEl) {
            listEl.innerHTML = '';
            listEl.classList.add('d-none');
        }
    };

    const hideLoading = () => {
        if (loadingEl) {
            loadingEl.hidden = true;
            loadingEl.classList.add('d-none');
        }
    };

    document.addEventListener('click', function(evt) {
        const cell = evt.target.closest('.heatmap-cell.has-score');
        if (!cell || !root.contains(cell)) {
            return;
        }

        evt.preventDefault();

        const periodKey = cell.getAttribute('data-period-key');
        if (!periodKey) {
            return;
        }

        const label = cell.getAttribute('data-label') || periodKey;
        const scoreAttr = cell.getAttribute('data-score');
        const kAttr = cell.getAttribute('data-k-count');
        const suffix = [];
        const scoreNum = scoreAttr ? Number(scoreAttr) : NaN;
        if (!Number.isNaN(scoreNum)) {
            suffix.push('score ' + scoreNum.toFixed(3));
        }
        const kNum = kAttr ? Number(kAttr) : NaN;
        if (!Number.isNaN(kNum)) {
            suffix.push('docs ' + kNum);
        }
        if (titleEl) {
            titleEl.textContent = suffix.length ? label + ' (' + suffix.join(' • ') + ')' : label;
        }

        showLoading();
        modal.show();

        const body = new URLSearchParams();
        body.set('q', config.query);
        body.set('period_key', periodKey);
        body.set('granularity', config.granularity || 'month');
        if (config.range_from) {
            body.set('range_from', config.range_from);
        }
        if (config.range_to) {
            body.set('range_to', config.range_to);
        }
        body.set('k', String(config.k || 3));

        fetch('index2.php?action=heatmap_bucket', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        })
            .then(res => {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(data => {
                hideLoading();
                if (!data || data.ok !== true || !Array.isArray(data.items)) {
                    const errMsg = data && data.error ? data.error : 'Unexpected response';
                    throw new Error(errMsg);
                }

                const items = data.items;
                if (!items.length) {
                    if (emptyEl) {
                        emptyEl.classList.remove('d-none');
                    }
                    return;
                }

                if (!listEl) {
                    return;
                }

                const fragment = document.createDocumentFragment();
                items.forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item';
                    const title = escapeHtml(item.title || 'Untitled document');
                    const meta = [];
                    if (item.pubname) meta.push(item.pubname);
                    if (item.date) meta.push(item.date);
                    if (typeof item.score === 'number') meta.push('score ' + item.score.toFixed(3));
                    const summary = escapeHtml(item.summary || '');
                    let linkHtml = '';
                    if (typeof item.page_id === 'number' && item.page_id > 0 && item.pubname) {
                        const journalParam = encodeURIComponent(String(item.pubname));
                        const pageParam = encodeURIComponent(String(item.page_id));
                        const url = 'https://nilla.local/semantic/page_json.php?page_id=' + pageParam + '&journal=' + journalParam;
                        linkHtml = '<div class="mt-2"><a class="small" href="' + url + '" target="_blank" rel="noopener">View article page</a></div>';
                    }
                    li.innerHTML = '<div class="fw-semibold">' + title + '</div>' +
                        '<div class="text-body-secondary small mb-2">' + escapeHtml(meta.join(' • ')) + '</div>' +
                        '<div class="text-body-secondary small">' + summary + '</div>' +
                        linkHtml;
                    fragment.appendChild(li);
                });
                listEl.innerHTML = '';
                listEl.appendChild(fragment);
                listEl.classList.remove('d-none');
            })
            .catch(err => {
                hideLoading();
                if (errorEl) {
                    errorEl.textContent = err.message;
                    errorEl.classList.remove('d-none');
                }
            });
    });
})();
</script>



</body>
</html>

