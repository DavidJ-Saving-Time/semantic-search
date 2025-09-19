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

$selectedPub = isset($_POST['pubname']) ? trim($_POST['pubname']) : '';

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
        try {
            $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD, $PG_STMT_TIMEOUT);

            // cache
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

            // Build vector literal
            // Build the vector literal (same as before)
            $vec = vector_literal($emb); // "[0.12,0.34,...]"

            // Use native pgsql just for this query
            $pgconn = pg_connect(sprintf(
                "host=%s port=%s dbname=%s user=%s%s",
                $PGHOST,
                $PGPORT,
                $PGDATABASE,
                $PGUSER,
                ($PGPASSWORD !== '' ? " password={$PGPASSWORD}" : "")
            ));
            if ($pgconn === false) {
                throw new RuntimeException('pg_connect failed: ' . pg_last_error());
            }

            pg_query($pgconn, "SET jit = off");
            pg_query($pgconn, "SET statement_timeout = 0");
            pg_query($pgconn, 'SET enable_sort = off');
            //pg_query($pgconn, 'SET hnsw.ef_search = 80');

            // PHP params order (leave as-is):
            // [$vec, $w_sim, $w_topic, $thresh, $k, $limit]
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
       p.page_id
FROM scored s
JOIN docs d ON d.id = s.id
LEFT JOIN pages p ON p.issue = d.issue AND p.page = d.page
ORDER BY final_score DESC
LIMIT $6;
SQL;

            $t0 = microtime(true);
            $pubnameParam = ($selectedPub === '') ? null : $selectedPub;
            $params = [$vec, $w_sim, $w_topic, $thresh, $k, $limit, $pubnameParam];
            $res = pg_query_params($pgconn, $sql_pg, $params);



            if ($res === false) {
                throw new RuntimeException('pg_query_params failed: ' . pg_last_error($pgconn));
            }
            $results = pg_fetch_all($res) ?: [];
            $timing['sql_ms'] = (int)round((microtime(true) - $t0) * 1000);

            pg_close($pgconn);




        } catch (Throwable $e) {
            $errorMsg = 'Search failed. See server logs.';
            error_log('[semantic_search] ' . $e->getMessage());
        }
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
                  data-doc-title="<?= h(english_title_case($row['title'] ?? '')) ?>">
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

      <h2 class="h5 mt-2 mb-1"><?=english_title_case($row['title']);?></h2>
      <div class="snippet"><?=h($row['snippet'] ?? '')?></div>

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
  <!-- Historical Context Modal -->
  <div class="modal fade" id="contextModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa-solid fa-landmark me-2"></i><span id="contextModalTitle">Historical Context</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="contextLoading" class="d-flex align-items-center gap-2 mb-3" hidden>
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Contacting Claude…</span>
          </div>
          <div id="contextError" class="alert alert-danger d-none" role="alert"></div>
          <article id="contextContent" class="markdown-body"></article>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
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
    const modalEl = document.getElementById('contextModal');
    if (!modalEl) {
        return;
    }

    const modal = new bootstrap.Modal(modalEl);
    const titleEl = document.getElementById('contextModalTitle');
    const loadingEl = document.getElementById('contextLoading');
    const errorEl = document.getElementById('contextError');
    const contentEl = document.getElementById('contextContent');

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

        const docTitle = btn.getAttribute('data-doc-title') || 'Historical Context';
        titleEl.textContent = docTitle;
        contentEl.innerHTML = '';
        errorEl.classList.add('d-none');
        if (loadingEl) {
            loadingEl.hidden = false;
        }

        const previousDisabled = btn.disabled;
        btn.disabled = true;

        modal.show();

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
            })
            .catch(err => {
                errorEl.textContent = 'Failed to load historical context: ' + err.message;
                errorEl.classList.remove('d-none');
            })
            .finally(() => {
                if (loadingEl) {
                    loadingEl.hidden = true;
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
</script>



</body>
</html>

