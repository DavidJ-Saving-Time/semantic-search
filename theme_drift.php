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

if ($action === 'nearest_docs' || $action === 'topic_labels' || $action === 'drift') {
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
                    <div id="chart" class="w-100"></div>
                    <div id="status" class="mt-2 text-muted small">Adjust the filters to load embeddings.</div>
                </div>
            </div>
            <div id="summary" class="mb-3"></div>
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
        let latestPayload = null;
        let isLoading = false;
        let selectedItem = null;
        let selectionStamp = 0;

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

        const defaultHelperMessage = helperIntro ? helperIntro.textContent : 'Select a point in the chart to unlock quick lookups.';
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        const clearHelperOutputs = () => {
            if (helperDocsResult) {
                helperDocsResult.innerHTML = '';
            }
            if (helperTopicsResult) {
                helperTopicsResult.innerHTML = '';
            }
            if (helperDriftResult) {
                helperDriftResult.innerHTML = '';
            }
        };

        const setHelpersEnabled = (enabled, options = {}) => {
            const {clearOutputs = false} = options;
            [helperDocsButton, helperTopicsButton, helperDriftButton].forEach((btn) => {
                if (!btn) {
                    return;
                }
                btn.disabled = !enabled;
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

        const renderDocsResult = (docs, selection) => {
            if (!Array.isArray(docs) || docs.length === 0) {
                return '<div class="small text-muted">No articles found for this period.</div>';
            }
            let html = '';
            docs.forEach((doc) => {
                const metaParts = [];
                if (doc.date) {
                    metaParts.push(doc.date);
                }
                if (doc.pubname) {
                    metaParts.push(doc.pubname);
                }
                if (doc.id != null) {
                    metaParts.push(`ID ${doc.id}`);
                }
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

        const runHelper = async (type) => {
            if (!selectedItem) {
                return;
            }
            const buttonMap = {
                docs: helperDocsButton,
                topics: helperTopicsButton,
                drift: helperDriftButton,
            };
            const resultMap = {
                docs: helperDocsResult,
                topics: helperTopicsResult,
                drift: helperDriftResult,
            };
            const button = buttonMap[type];
            const container = resultMap[type];
            if (!button || !container) {
                return;
            }

            const selectionSnapshot = selectedItem;
            const currentStamp = selectionStamp;

            button.disabled = true;
            container.innerHTML = '<div class="small text-muted">Loading…</div>';

            const params = new URLSearchParams();
            const periodValue = selectionSnapshot.period || (latestPayload ? latestPayload.period : 'year');
            params.set('period', periodValue || 'year');
            params.set('period_key', selectionSnapshot.period_key || '');
            params.set('aggregation', selectionSnapshot.is_combined ? 'combined' : 'per_pub');
            if (!selectionSnapshot.is_combined && selectionSnapshot.pubname) {
                params.set('pubname', selectionSnapshot.pubname);
            }
            if (type === 'docs') {
                params.set('limit', '10');
            }
            if (type === 'topics') {
                params.set('limit', '5');
            }

            let actionName = '';
            if (type === 'docs') {
                actionName = 'nearest_docs';
            } else if (type === 'topics') {
                actionName = 'topic_labels';
            } else if (type === 'drift') {
                actionName = 'drift';
            } else {
                button.disabled = false;
                return;
            }

            try {
                const response = await fetch(`theme_drift.php?action=${actionName}&${params.toString()}`);
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                const payload = await response.json();
                if (!payload.ok) {
                    throw new Error(payload.error || 'Unknown error');
                }
                if (currentStamp !== selectionStamp) {
                    return;
                }
                if (type === 'docs') {
                    container.innerHTML = renderDocsResult(payload.docs || [], selectionSnapshot);
                } else if (type === 'topics') {
                    container.innerHTML = renderTopicsResult(payload.topics || []);
                } else if (type === 'drift') {
                    container.innerHTML = renderDriftResult(payload, selectionSnapshot);
                }
            } catch (err) {
                if (currentStamp !== selectionStamp) {
                    return;
                }
                container.innerHTML = `<div class="text-danger small">${escapeHtml(err && err.message ? err.message : 'Failed to load helper data.')}</div>`;
            } finally {
                if (currentStamp === selectionStamp) {
                    button.disabled = false;
                }
            }
        };

        setHelpersEnabled(false);

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

        const renderPlot = async (payload) => {
            if (!payload || !payload.items || payload.items.length === 0) {
                Plotly.purge(chartDiv);
                statusEl.textContent = 'No embeddings matched the current filters.';
                updateSummary(null);
                resetDetail();
                return;
            }

            const neighborsInput = document.getElementById('neighbors');
            const minDistInput = document.getElementById('minDist');

            const nNeighbors = Math.max(5, Math.min(60, parseInt(neighborsInput.value, 10) || 15));
            const minDist = Math.max(0.01, Math.min(0.99, parseFloat(minDistInput.value) || 0.15));

            const vectors = payload.items.map((item) => item.embedding);
            statusEl.textContent = `Computing UMAP projection for ${vectors.length} vectors…`;
            await new Promise((resolve) => setTimeout(resolve, 50));

            const umap = new window.UMAP({
                nNeighbors,
                minDist,
                nComponents: 2,
                random: Math.random
            });

            const coords = await umap.fitAsync(vectors);
            const xs = [];
            const ys = [];
            const colors = [];
            const texts = [];
            const custom = [];
            const fallbackYear = (typeof payload.min_year === 'number' && !Number.isNaN(payload.min_year)) ? payload.min_year : 0;

            payload.items.forEach((item, idx) => {
                const coord = coords[idx];
                xs.push(coord[0]);
                ys.push(coord[1]);
                const yearVal = (typeof item.year === 'number' && !Number.isNaN(item.year)) ? item.year : fallbackYear;
                colors.push(yearVal);
                const title = item.label || item.period_start || `Period ${idx + 1}`;
                const publication = item.is_combined ? 'Combined' : (item.pubname || 'Unknown publication');
                texts.push(`${title}<br>${publication}`);
                custom.push([
                    item.label || '',
                    item.period_start || '',
                    item.period_end || '',
                    item.article_count || 0,
                    publication,
                    item.model || 'unknown',
                    item.dim || 0,
                    item.token_count || 0
                ]);
            });

            const minYear = (typeof payload.min_year === 'number' && !Number.isNaN(payload.min_year))
                ? payload.min_year
                : Math.min(...colors);
            const maxYear = (typeof payload.max_year === 'number' && !Number.isNaN(payload.max_year))
                ? payload.max_year
                : Math.max(...colors);

            const trace = {
                type: 'scattergl',
                mode: 'markers',
                x: xs,
                y: ys,
                text: texts,
                customdata: custom,
                hovertemplate: '<b>%{customdata[0]}</b><br>Period: %{customdata[1]} → %{customdata[2]}<br>Articles: %{customdata[3]:,}<br>Publication: %{customdata[4]}<extra></extra>',
                marker: {
                    size: 9,
                    opacity: 0.85,
                    color: colors,
                    colorscale: 'Viridis',
                    colorbar: {
                        title: 'Year'
                    },
                    cmin: minYear,
                    cmax: maxYear,
                    line: {
                        width: 0.5,
                        color: 'rgba(0,0,0,0.3)'
                    }
                }
            };

            const layout = {
                dragmode: 'pan',
                hovermode: 'closest',
                margin: {l: 40, r: 20, t: 20, b: 40},
                paper_bgcolor: '#f8f9fa',
                plot_bgcolor: '#f8f9fa',
                xaxis: {
                    title: 'UMAP 1',
                    showgrid: false,
                    zeroline: false
                },
                yaxis: {
                    title: 'UMAP 2',
                    showgrid: false,
                    zeroline: false
                },
                height: 600
            };

            Plotly.purge(chartDiv);
            await Plotly.newPlot(chartDiv, [trace], layout, {responsive: true, displaylogo: false});

            statusEl.textContent = `Rendered ${payload.count} periods. Use zoom & pan to inspect clusters.`;
            latestPayload = Object.assign({}, payload, {coords});
            updateSummary(payload);
            resetDetail();

            chartDiv.on('plotly_click', (eventData) => {
                if (!eventData || !eventData.points || !eventData.points.length) {
                    return;
                }
                const point = eventData.points[0];
                const idx = point.pointIndex;
                const item = payload.items[idx];
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
                    period: payload.period || 'year',
                };
                selectionStamp += 1;
                selectedItem = selection;
                setHelpersEnabled(true, {clearOutputs: true});
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
            });
        };

        const loadData = async () => {
            if (isLoading) {
                return;
            }
            isLoading = true;
            statusEl.textContent = 'Loading period embeddings…';
            summaryEl.innerHTML = '';
            resetDetail();

            const formData = new FormData(form);
            const params = new URLSearchParams();
            params.set('period', formData.get('period') || 'year');
            params.set('aggregation', aggregationSelect.value || 'combined');
            const startYearVal = formData.get('start_year');
            if (startYearVal) {
                params.set('start_year', startYearVal);
            }
            const endYearVal = formData.get('end_year');
            if (endYearVal) {
                params.set('end_year', endYearVal);
            }
            const limitVal = formData.get('limit');
            if (limitVal) {
                params.set('limit', limitVal);
            }
            if (aggregationSelect.value === 'per_pub') {
                const pubVal = formData.get('pubname');
                if (pubVal) {
                    params.set('pubname', pubVal);
                }
            }

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
            } finally {
                isLoading = false;
            }
        };

        if (helperDocsButton) {
            helperDocsButton.addEventListener('click', () => {
                runHelper('docs');
            });
        }
        if (helperTopicsButton) {
            helperTopicsButton.addEventListener('click', () => {
                runHelper('topics');
            });
        }
        if (helperDriftButton) {
            helperDriftButton.addEventListener('click', () => {
                runHelper('drift');
            });
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            loadData();
        });

        aggregationSelect.addEventListener('change', () => {
            updateAggregationState();
        });

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

        updateAggregationState();
        loadData();
    });
})();
</script>
</body>
</html>
