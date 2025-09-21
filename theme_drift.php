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
                    <p class="small text-muted">Drop these snippets into a SQL client to jump from the visualization to the underlying records.</p>
                    <div class="mb-4">
                        <h4 class="h6">A) Inspect a month&rsquo;s nearest articles</h4>
                        <p class="small text-muted">Swap <code>:key</code> for a <code>period_key</code> such as <code>&#039;1851-05&#039;</code>.</p>
                    <pre class="bg-light p-3 small overflow-auto"><code>WITH m AS (
  SELECT embedding_hv FROM public.period_embeddings
  WHERE period = 'month' AND is_combined = TRUE AND period_key = :key
)
SELECT id, pubname, date, summary, (1 - d.embedding_hv &lt;=&gt; m.embedding_hv) AS sim
FROM public.docs d, m
WHERE date &gt;= to_date(:key || '-01', 'YYYY-MM-DD')
  AND date &lt;  (to_date(:key || '-01', 'YYYY-MM-DD') + INTERVAL '1 month')
ORDER BY d.embedding_hv &lt;=&gt; m.embedding_hv
LIMIT 10;</code></pre>
                    </div>
                    <div class="mb-4">
                        <h4 class="h6">B) Auto-label a month with curated topics</h4>
                    <pre class="bg-light p-3 small overflow-auto"><code>WITH m AS (
  SELECT embedding_hv FROM public.period_embeddings
  WHERE period = 'month' AND is_combined = TRUE AND period_key = :key
)
SELECT topic
FROM public.topic_labels t, m
ORDER BY t.emb &lt;=&gt; m.embedding_hv
LIMIT 5;</code></pre>
                    </div>
                    <div class="mb-4">
                        <h4 class="h6">C) Spot big month-to-month jumps</h4>
                    <pre class="bg-light p-3 small overflow-auto"><code>WITH months AS (
  SELECT period_key,
         to_date(period_key || '-01', 'YYYY-MM-DD') AS d,
         embedding_hv
  FROM public.period_embeddings
  WHERE period = 'month' AND is_combined = TRUE
    AND period_key BETWEEN '1850-01' AND '1859-12'
),
seq AS (
  SELECT *, LAG(embedding_hv) OVER (ORDER BY d) AS prev_hv
  FROM months
)
SELECT period_key,
       (prev_hv &lt;=&gt; embedding_hv) AS dist_to_prev
FROM seq
WHERE prev_hv IS NOT NULL
ORDER BY d;</code></pre>
                        <p class="small text-muted mb-0">Highest cosine distances often mark regime changes (for example, <code>1854-03/04</code> or <code>1857-05/06</code>).</p>
                    </div>
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
                        <li>On hover, surface the top three nearest topics (query B) and a few representative articles (query A).</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/umap-js@1.3.3/lib/umap.min.js" integrity="sha384-GU5uO3gl5mOWYMVkXuxXWEX0k/eMn7IbkL0rIZdyIJ/kEHF4Pz3mmI95T9Ipp1A7" crossorigin="anonymous"></script>
<script src="https://cdn.plot.ly/plotly-2.27.0.min.js" integrity="sha384-FOukw0cVwY0GO+bMC9gU33JM16KU0p1Dwmtb00tCyy6PuCl0UCaMzFxLpy0X58F1" crossorigin="anonymous"></script>
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
        let latestPayload = null;
        let isLoading = false;

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

        const updateAggregationState = () => {
            const isCombined = aggregationSelect.value !== 'per_pub';
            pubnameSelect.disabled = isCombined;
            if (isCombined) {
                pubnameSelect.value = '';
            }
        };

        const resetDetail = () => {
            detailContent.innerHTML = 'Click a point in the chart to inspect its metadata.';
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
                const publication = item.is_combined ? 'Combined corpus' : (item.pubname || 'Unknown publication');
                detailContent.innerHTML = `
                    <div><strong>${escapeHtml(item.label || item.period_start || 'Selected period')}</strong></div>
                    <div class="small text-muted">${escapeHtml(publication)}</div>
                    <hr>
                    <div><strong>Period:</strong> ${escapeHtml(item.period_start || '?')} → ${escapeHtml(item.period_end || '?')}</div>
                    <div><strong>Articles:</strong> ${item.article_count ? item.article_count.toLocaleString() : 'n/a'}</div>
                    <div><strong>Tokens:</strong> ${item.token_count ? item.token_count.toLocaleString() : 'n/a'}</div>
                    <div><strong>Model:</strong> ${escapeHtml(item.model || 'unknown')} &middot; dim ${item.dim || 'n/a'}</div>
                    <div><strong>Database key:</strong> ${escapeHtml(item.period_key || String(item.id || '?'))}</div>
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
