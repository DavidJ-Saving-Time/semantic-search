<?php
ini_set('display_errors', '0');
setlocale(LC_NUMERIC, 'C');

$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pg_pdo(string $host, string $port, string $db, string $user, string $pass, int $stmtTimeoutMs = 0): PDO
{
    $app = 'semantic_search_page_view';
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--application_name={$app}'";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);

    if ($stmtTimeoutMs > 0) {
        $pdo->exec('SET statement_timeout = ' . (int)$stmtTimeoutMs);
    }

    $pdo->exec('SET jit = off');

    return $pdo;
}

$docData = [
    'error' => null,
    'pageId' => null,
    'pageBase' => null,
    'pageLabel' => null,
    'prevId' => null,
    'nextId' => null,
    'searchQuery' => isset($_GET['q']) ? (string)$_GET['q'] : '',
    'meta' => [
        'pubname' => null,
        'date' => null,
        'title' => null,
        'journal' => null,
        'issue' => null,
        'firstPage' => null,
    ],
];

$httpStatus = 200;
$pageTitle = 'Page Image + Text Overlay (JSON + Zoom)';

$pageParam = $_GET['page'] ?? '';
if ($pageParam === '' || !preg_match('/^\d+$/', (string)$pageParam)) {
    $docData['error'] = 'Invalid or missing page identifier.';
    $httpStatus = 400;
} else {
    $docData['pageId'] = (int)$pageParam;

    try {
        $pdo = pg_pdo($PGHOST, $PGPORT, $PGDATABASE, $PGUSER, $PGPASSWORD, 5000);

        $stmt = $pdo->prepare("SELECT d.id, d.pubname, d.date, d.meta->>'journal' AS journal, d.meta->>'issue' AS issue, d.meta->>'title' AS title, d.meta->>'first_page' AS first_page_raw FROM docs d WHERE d.id = :id");
        $stmt->execute([':id' => $docData['pageId']]);
        $row = $stmt->fetch();

        if (!$row) {
            $docData['error'] = 'Requested page was not found.';
            $httpStatus = 404;
        } else {
            $journal = isset($row['journal']) ? trim((string)$row['journal'], '/') : '';
            $issue = isset($row['issue']) ? trim((string)$row['issue'], '/') : '';
            $firstPage = null;
            $firstPageRaw = $row['first_page_raw'] ?? null;
            if (is_string($firstPageRaw) && preg_match('/^\d+$/', $firstPageRaw)) {
                $firstPage = (int)$firstPageRaw;
            }

            $docData['meta'] = [
                'pubname' => $row['pubname'] ?? null,
                'date' => $row['date'] ?? null,
                'title' => $row['title'] ?? null,
                'journal' => $journal,
                'issue' => $issue,
                'firstPage' => $firstPage,
            ];

            if ($journal !== '' && $issue !== '' && $firstPage !== null) {
                $pageSlug = 'page-' . str_pad((string)max(0, $firstPage), 4, '0', STR_PAD_LEFT);
                $docData['pageBase'] = '/' . $journal . '/' . $issue . '/pages/' . $pageSlug;
                $docData['pageLabel'] = $pageSlug;

                $pageTitleParts = [];
                if (!empty($row['pubname'])) {
                    $pageTitleParts[] = $row['pubname'];
                }
                if (!empty($row['date'])) {
                    $pageTitleParts[] = $row['date'];
                }
                $pageTitleParts[] = $pageSlug;
                $pageTitle = implode(' – ', array_filter($pageTitleParts));

                $prevStmt = $pdo->prepare("SELECT d.id FROM docs d WHERE d.meta->>'journal' = :journal AND d.meta->>'issue' = :issue AND d.meta->>'first_page' ~ '^[0-9]+$' AND (d.meta->>'first_page')::int < :first_page ORDER BY (d.meta->>'first_page')::int DESC LIMIT 1");
                $prevStmt->execute([
                    ':journal' => $journal,
                    ':issue' => $issue,
                    ':first_page' => $firstPage,
                ]);
                $prevRow = $prevStmt->fetch();
                if ($prevRow && isset($prevRow['id'])) {
                    $docData['prevId'] = (int)$prevRow['id'];
                }

                $nextStmt = $pdo->prepare("SELECT d.id FROM docs d WHERE d.meta->>'journal' = :journal AND d.meta->>'issue' = :issue AND d.meta->>'first_page' ~ '^[0-9]+$' AND (d.meta->>'first_page')::int > :first_page ORDER BY (d.meta->>'first_page')::int ASC LIMIT 1");
                $nextStmt->execute([
                    ':journal' => $journal,
                    ':issue' => $issue,
                    ':first_page' => $firstPage,
                ]);
                $nextRow = $nextStmt->fetch();
                if ($nextRow && isset($nextRow['id'])) {
                    $docData['nextId'] = (int)$nextRow['id'];
                }
            } else {
                $docData['error'] = 'This record is missing page asset information.';
                $httpStatus = 422;
            }
        }
    } catch (Throwable $e) {
        $docData['error'] = 'Unable to load page details.';
        $httpStatus = 500;
    }
}

http_response_code($httpStatus);
$docJson = json_encode($docData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?=h($pageTitle)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --accent: rgba(255, 200, 0, 0.35);
      --accent-strong: rgba(255, 120, 0, 0.6);
      --box: rgba(0, 120, 255, 0.18);
      --box-border: rgba(0, 120, 255, 0.4);
      --bg: #0b0f14;
      --fg: #e6eef7;
      --muted: #9ab;
      --header-h: 0px;
    }
    html, body { height: 100%; margin: 0; background: var(--bg); color: var(--fg); font: 14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, "Helvetica Neue", Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
    header { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; padding: .75rem 1rem; position: fixed; top: 0; left: 0; right: 0; background: linear-gradient(to bottom, rgba(0,0,0,.65), rgba(0,0,0,.35), transparent); backdrop-filter: blur(6px); z-index: 10; }
    header input[type="search"] { flex: 1 1 280px; padding: .6rem .75rem; border-radius: .6rem; border: 1px solid #334; background: #0f1621; color: var(--fg); }
    header button, header label { padding: .55rem .8rem; border-radius: .6rem; border: 1px solid #334; background: #0f1621; color: var(--fg); cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; }
    header .meta { font-size: 12px; color: var(--muted); }
    .zoom-wrap { display: inline-flex; align-items: center; gap: .5rem; padding: .4rem .6rem; border-radius: .6rem; border: 1px solid #334; background: #0f1621; }
    .zoom-wrap input[type="range"] { width: 160px; }

    .stage { display: grid; place-content: start center; padding: 1rem; padding-top: calc(var(--header-h) + 1rem); }
    /* Align content to top-left when panning so horizontal dragging works in both directions */
    body.pan .stage { place-content: start; }
    .page-outer { position: relative; }
    .page-wrap { position: relative; transform-origin: top left; }
    .page-wrap img { width: 100%; height: auto; display: block; box-shadow: 0 10px 30px rgba(0,0,0,.45); border-radius: .5rem; }

    .overlay { position: absolute; inset: 0; pointer-events: auto; }
    .word { position: absolute; outline: 1px solid transparent; white-space: pre; background: transparent; user-select: text; color: transparent; -webkit-text-fill-color: transparent; caret-color: transparent; }
    .word::selection { background: var(--accent); }
    body.debug .word { outline-color: var(--box-border); background: var(--box); }
    .word.hit { background: var(--accent); outline-color: var(--accent-strong); border-radius: 2px; }

    .toast { position: fixed; right: 12px; bottom: 12px; background: rgba(20,28,38,.8); border: 1px solid #334; border-radius: .6rem; padding: .5rem .7rem; color: var(--muted); font-size: 12px; z-index: 20; }
    .legend { color: var(--muted); font-size: 12px; margin-left: auto; }
    a { color: #9fd; }
    .error-banner { margin: calc(var(--header-h) + 1rem) 1rem 0; background: rgba(140, 30, 30, 0.7); border: 1px solid rgba(200, 80, 80, 0.9); border-radius: .6rem; padding: .75rem 1rem; color: #fdd; }
    #citeModal .modal-dialog { max-width: 720px; }
    #citeOutput { min-height: 200px; line-height: 1.6; }
  </style>
</head>
<body>
  <header>
    <input id="q" type="search" placeholder="Find: 'the bay,my house in'" autocomplete="off" />
    <button id="btnFind" title="Find">Find</button>
    <button id="btnClear" title="Clear">Clear</button>
    <label id="lblDebug" title="Toggle debug outlines"><input id="chkDebug" type="checkbox" /> Debug</label>
    <button id="btnCite" title="Cite">Cite</button>


    <span class="zoom-wrap" title="Zoom (Ctrl + / Ctrl - / Ctrl 0)">
      <button id="zoomOut" aria-label="Zoom out">−</button>
      <input id="zoomRange" type="range" min="50" max="300" step="1" value="100" />
      <button id="zoomIn" aria-label="Zoom in">+</button>
      <button id="zoomFit" title="Fit width">Fit</button>
      <span id="zoomLabel" class="meta">100%</span>
    </span>


  </header>

  <main class="stage">
    <div class="page-outer">
      <div class="page-wrap" id="pageWrap">
        <img id="pageImg" alt="page image" />
        <div id="overlay" class="overlay"></div>
      </div>
    </div>
  </main>

  <?php if (!empty($docData['error'])): ?>
    <div class="error-banner"><?=h($docData['error'])?></div>
  <?php endif; ?>

  <div id="toast" class="toast" hidden>0 matches</div>

  <div class="modal fade" id="citeModal" tabindex="-1" aria-labelledby="citeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content bg-dark text-light">
        <div class="modal-header border-secondary">
          <h1 class="modal-title fs-5" id="citeModalLabel">Cite this page</h1>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label for="citeOutput" class="form-label">Oxford reference style</label>
          <textarea id="citeOutput" class="form-control" rows="7" readonly></textarea>
        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <footer>
  <button id="prevPage">⟨ Prev</button>
  <span id="pageLabel">page-0001</span>
  <button id="nextPage">Next ⟩</button>
</footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
  const docData = <?=$docJson?>;
  const hasDocData = !!(docData && !docData.error && docData.pageBase);

  // === Elements ===
  const pageWrap = document.getElementById('pageWrap');
  const img = document.getElementById('pageImg');
  const overlay = document.getElementById('overlay');
  const input = document.getElementById('q');
  const btnFind = document.getElementById('btnFind');
  const btnClear = document.getElementById('btnClear');
  const toast = document.getElementById('toast');
  const chkDebug = document.getElementById('chkDebug');
  const chkOrigin = document.getElementById('chkOrigin');
  const zoomRange = document.getElementById('zoomRange');
  const zoomInBtn = document.getElementById('zoomIn');
  const zoomOutBtn = document.getElementById('zoomOut');
  const zoomFitBtn = document.getElementById('zoomFit');
  const zoomLabel = document.getElementById('zoomLabel');
  const headerEl = document.querySelector('header');
  const pageLabelEl = document.getElementById('pageLabel');
  const prevBtn = document.getElementById('prevPage');
  const nextBtn = document.getElementById('nextPage');
  const citeBtn = document.getElementById('btnCite');
  const citeOutputEl = document.getElementById('citeOutput');
  const citeModalEl = document.getElementById('citeModal');
  const citeModal = (typeof bootstrap !== 'undefined' && citeModalEl) ? new bootstrap.Modal(citeModalEl) : null;

  if (pageLabelEl) {
    pageLabelEl.textContent = (docData && docData.pageLabel) ? docData.pageLabel : 'page-0001';
  }

  function formatCitation() {
    const meta = docData && docData.meta ? docData.meta : {};
    const author = (meta.pubname && String(meta.pubname).trim()) ? String(meta.pubname).trim() : 'Unknown';
    const title = (meta.title && String(meta.title).trim()) ? String(meta.title).trim() : (docData && docData.pageLabel ? docData.pageLabel : 'Untitled page');
    const website = 'Nillas Archive';
    const journal = (meta.journal && String(meta.journal).trim()) ? String(meta.journal).trim() : '';
    const issue = (meta.issue && String(meta.issue).trim()) ? String(meta.issue).trim() : '';

    let year = 'n.d.';
    if (meta.date) {
      const dateStr = String(meta.date);
      const match = dateStr.match(/(\d{4})/);
      if (match) {
        year = match[1];
      }
    }

    const { origin, pathname, hash } = window.location;
    const url = `${origin}${pathname}${hash ?? ''}`;
    const now = new Date();
    const accessed = now.toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });

    const pageCitation = `${author}, '${title}', ${website}, (${year}), <${url}> [accessed ${accessed}].`;

    const bookTitle = journal || title;
    const detailParts = [];
    if (issue) {
      detailParts.push(issue);
    }
    if (year !== 'n.d.') {
      detailParts.push(year);
    }
    const detailSuffix = detailParts.length ? ` (${detailParts.join(', ')})` : '';
    const bookCitation = `${author}, ${bookTitle}${detailSuffix}.`;

    return [pageCitation, bookCitation].join('\n\n');
  }

  if (citeBtn && citeModal && citeOutputEl) {
    citeBtn.addEventListener('click', () => {
      citeOutputEl.value = formatCitation();
      citeModal.show();
      citeOutputEl.focus();
      citeOutputEl.select();
    });
  }

  // === State ===
  let pdfW = null, pdfH = null;
  let rotation = 0;
  let originTopLeft = true;
  let words = [];

  // Phrase-search index (built from words)
  let searchJoined = '';
  let searchMap = []; // per character: { wordIndex, isSpace }

  // Zoom state
  let zoom = 1; // 1 = 100%
  let fitMode = 'fit'; // start in fit-to-width mode
  let zoomAnimation = null;

  const ZOOM_MIN = 0.5;
  const ZOOM_MAX = 3.0;
  const WHEEL_LINE_HEIGHT = 16;
  const WHEEL_ZOOM_SENSITIVITY = 0.0015;
  const ZOOM_SMOOTH_TAU = 120;
  const GESTURE_IDLE_MS = 140;
  const MIN_ANIM_DIFF = 0.0005;

  const clampZoom = value => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, value));

  function normalizeWheelDeltaY(evt) {
    if (!evt) return 0;
    if (evt.deltaMode === 1) return evt.deltaY * WHEEL_LINE_HEIGHT; // DOM_DELTA_LINE
    if (evt.deltaMode === 2) return evt.deltaY * (window.innerHeight || 1); // DOM_DELTA_PAGE
    return evt.deltaY;
  }


  // Pan/drag state
let panMode = false;
let isPanning = false;
let panStart = { x: 0, y: 0, scrollX: 0, scrollY: 0 };

let wheelGesture = null;
let wheelRaf = 0;



  // === Helpers ===
  function showToast(msg) {
    toast.textContent = msg;
    toast.hidden = false;
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => { toast.hidden = true; }, 11112200);
  }

  function isEditableTarget(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    const tag = (el.tagName || '').toUpperCase();
    return tag === 'INPUT' || tag === 'TEXTAREA';
  }

  function updateHeaderOffset() {
    document.documentElement.style.setProperty('--header-h', `${headerEl.offsetHeight}px`);
  }

  updateHeaderOffset();


  function updateContainerSize() {
    const outer = pageWrap.parentElement;
    const nw = img.naturalWidth;
    const nh = img.naturalHeight;
    if (!nw || !nh) return;
    pageWrap.style.width = `${nw}px`;
    pageWrap.style.height = `${nh}px`;
    outer.style.width = `${nw * zoom}px`;
    outer.style.height = `${nh * zoom}px`;
  }


  function setZoom(z, opts = {}) {
    const targetZoom = clampZoom(z);

    const hasAnchor = opts.anchor && opts.anchorClient
      && Number.isFinite(opts.anchor.x) && Number.isFinite(opts.anchor.y)
      && Number.isFinite(opts.anchorClient.x) && Number.isFinite(opts.anchorClient.y)
      && Number.isFinite(opts.docLeft) && Number.isFinite(opts.docTop);

    const anchorData = hasAnchor
      ? {
          anchor: opts.anchor,
          anchorClient: opts.anchorClient,
          docLeft: opts.docLeft,
          docTop: opts.docTop,
        }
      : null;

    const adjustScroll = (data, currentZoom) => {
      const left = data.docLeft + data.anchor.x * currentZoom - data.anchorClient.x;
      const top = data.docTop + data.anchor.y * currentZoom - data.anchorClient.y;
      window.scrollTo({ left, top, behavior: 'auto' });
    };

    if (targetZoom === zoom) {
      if (anchorData) adjustScroll(anchorData, zoom);
      return zoom;
    }

    if (zoomAnimation && zoomAnimation.rafId) {
      cancelAnimationFrame(zoomAnimation.rafId);
      zoomAnimation = null;
    }

    const applyZoom = value => {
      zoom = value;
      pageWrap.style.transform = `scale(${zoom})`;
      const sliderValue = Math.round(zoom * 100);
      if (zoomRange) zoomRange.value = sliderValue;
      if (zoomLabel) zoomLabel.textContent = `${sliderValue}%`;
      // No layout() here — transform scales image and overlay together.
      updateContainerSize();
      if (anchorData) adjustScroll(anchorData, zoom);
    };

    if (opts.animate) {
      const duration = Math.max(0, Number(opts.duration) || 200);
      const startZoom = zoom;
      const diff = targetZoom - startZoom;
      const ease = typeof opts.easing === 'function'
        ? opts.easing
        : (t => 1 - Math.pow(1 - t, 3)); // easeOutCubic

      const startTime = performance.now();
      zoomAnimation = {};

      const step = now => {
        if (!zoomAnimation) return;
        const t = Math.min(1, (now - startTime) / duration);
        const eased = ease(t);
        applyZoom(startZoom + diff * eased);
        if (t < 1) {
          zoomAnimation.rafId = requestAnimationFrame(step);
        } else {
          zoomAnimation = null;
          applyZoom(targetZoom);
        }
      };

      zoomAnimation.rafId = requestAnimationFrame(step);
    } else {
      applyZoom(targetZoom);
    }

    return zoom;
  }


  function stopWheelAnimation() {
    if (wheelRaf) {
      cancelAnimationFrame(wheelRaf);
      wheelRaf = 0;
    }
    wheelGesture = null;
  }

  function wheelAnimate(now) {
    if (!wheelGesture) {
      wheelRaf = 0;
      return;
    }

    if (!panMode) {
      stopWheelAnimation();
      return;
    }

    const gesture = wheelGesture;
    const dt = Math.max(0, now - (gesture.lastTime || now));
    gesture.lastTime = now;

    const lambda = 1 - Math.exp(-dt / ZOOM_SMOOTH_TAU);
    const nextZoom = zoom + (gesture.targetZoom - zoom) * lambda;

    setZoom(nextZoom, {
      animate: false,
      anchor: gesture.anchor,
      anchorClient: gesture.anchorClient,
      docLeft: gesture.docLeft,
      docTop: gesture.docTop,
    });

    if ((now - gesture.idleAt) > GESTURE_IDLE_MS && Math.abs(zoom - gesture.targetZoom) < MIN_ANIM_DIFF) {
      setZoom(gesture.targetZoom, {
        animate: false,
        anchor: gesture.anchor,
        anchorClient: gesture.anchorClient,
        docLeft: gesture.docLeft,
        docTop: gesture.docTop,
      });
      stopWheelAnimation();
      return;
    }

    wheelRaf = requestAnimationFrame(wheelAnimate);
  }


  function togglePanMode() {
    panMode = !panMode;
    document.body.classList.toggle('pan', panMode);
    overlay.style.pointerEvents = panMode ? 'none' : 'auto';
    if (!panMode) {
      stopWheelAnimation();
      isPanning = false;
      document.body.classList.remove('panning');
      fitToWidth();
    }
    showToast(panMode ? 'Pan mode ON' : 'Pan mode OFF');
  }




  function computeFitZoom() {
    // Compute scale so the page fits the width of the stage container
    const outerEl = pageWrap.parentElement; // .page-outer
    const stageEl = outerEl.parentElement; // .stage
    const styles = getComputedStyle(stageEl);
    const padding = parseFloat(styles.paddingLeft) + parseFloat(styles.paddingRight);
    const targetWidth = Math.max(100, stageEl.clientWidth - padding);
    const naturalWidth = img.naturalWidth || 1;
    return targetWidth / naturalWidth;
  }

  function fitToWidth() {
    fitMode = 'fit';
    stopWheelAnimation();
    setZoom(computeFitZoom());
  }

  function layout() {
    if (!pdfW || !pdfH) return;
    const r = img.getBoundingClientRect();
    // Use pre-transform size for layout so zoom doesn't desync overlay.
    const scaleX = (r.width / zoom) / pdfW;
    const scaleY = (r.height / zoom) / pdfH;

    for (const w of words) {
      const bw = (w.xMax - w.xMin);
      const bh = (w.yMax - w.yMin);
      let left = 0, top = 0, width = bw * scaleX, height = bh * scaleY;

      if (rotation === 0) {
        if (originTopLeft) {
          left = w.xMin * scaleX;
          top  = w.yMin * scaleY;
        } else {
          left = w.xMin * scaleX;
          top  = (pdfH - w.yMax) * scaleY;
        }
      } else if (rotation === 90) {
        left = w.yMin * scaleX;
        top  = (pdfW - w.xMax) * scaleY;
        width = bh * scaleX;
        height = bw * scaleY;
      } else if (rotation === 180) {
        left = (pdfW - w.xMax) * scaleX;
        top  = (originTopLeft ? (pdfH - w.yMax) : w.yMin) * scaleY;
      } else if (rotation === 270) {
        left = (pdfH - w.yMax) * scaleX;
        top  = w.xMin * scaleY;
        width = bh * scaleX;
        height = bw * scaleY;
      }

      const el = w.el;
      el.style.left = left + 'px';
      el.style.top = top + 'px';
      el.style.width = width + 'px';
      el.style.height = height + 'px';
    }
  }

  // Normalize a single term (lowercase, collapse spaces)
  const normalizeTerm = s => String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();

  // Parse comma-separated list -> normalized array
  function parseQueryList(raw) {
    if (!raw) return [];
    return String(raw)
      .split(',')
      .map(part => normalizeTerm(part))
      .filter(Boolean);
  }

  // Highlight for multiple terms (each can be multi-word, spans boxes)
  function highlightAny(queryInput) {
    const terms = Array.isArray(queryInput) ? queryInput : parseQueryList(queryInput);

    for (const w of words) w.el.classList.remove('hit');

    if (!terms.length || !searchJoined) { showToast('0 matches'); return; }

    let firstEl = null;
    let total = 0;

    for (const term of terms) {
      if (!term) continue;
      let from = 0;
      while (true) {
        const at = searchJoined.indexOf(term, from);
        if (at === -1) break;
        const end = at + term.length;
        total++;

        const hitSet = new Set();
        for (let i = at; i < end && i < searchMap.length; i++) {
          const m = searchMap[i];
          if (m && !m.isSpace) hitSet.add(m.wordIndex);
        }
        for (const idx of hitSet) {
          const el = words[idx] && words[idx].el;
          if (el) {
            el.classList.add('hit');
            if (!firstEl) firstEl = el;
          }
        }
        from = at + 1;
      }
    }

    if (firstEl) {
      const r = firstEl.getBoundingClientRect();
      const y = window.scrollY + r.top - (window.innerHeight * 0.35);
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    }

    showToast(`${total} match${total === 1 ? '' : 'es'} across ${terms.length} term${terms.length === 1 ? '' : 's'}`);
  }


  // Pan events
const pageOuter = document.querySelector('.page-outer');
pageOuter.addEventListener('mousedown', (e) => {
if (!panMode) return;
isPanning = true;
document.body.classList.add('panning');
panStart.x = e.clientX;
panStart.y = e.clientY;
panStart.scrollX = window.scrollX;
panStart.scrollY = window.scrollY;
e.preventDefault();
});
window.addEventListener('mousemove', (e) => {
if (!isPanning) return;
const dx = e.clientX - panStart.x;
const dy = e.clientY - panStart.y;
window.scrollTo({ left: panStart.scrollX - dx, top: panStart.scrollY - dy });
});

// Mouse wheel zoom when in pan mode
window.addEventListener('wheel', (e) => {
  if (!panMode) return; // only zoom in pan mode

  const delta = normalizeWheelDeltaY(e);
  if (!delta) return;

  e.preventDefault();
  fitMode = 'custom';

  const now = performance.now();
  const startNewGesture = !wheelGesture || (now - wheelGesture.idleAt) > GESTURE_IDLE_MS;

  if (startNewGesture) {
    const rect = pageWrap.getBoundingClientRect();
    const targetZoom = clampZoom(zoom * Math.exp(-delta * WHEEL_ZOOM_SENSITIVITY));

    if (!rect || !rect.width || !rect.height) {
      stopWheelAnimation();
      setZoom(targetZoom, { animate: false });
      return;
    }

    const anchorClient = { x: e.clientX, y: e.clientY };
    const anchor = {
      x: (anchorClient.x - rect.left) / zoom,
      y: (anchorClient.y - rect.top) / zoom,
    };

    wheelGesture = {
      anchor,
      anchorClient,
      docLeft: rect.left + window.scrollX,
      docTop: rect.top + window.scrollY,
      targetZoom,
      idleAt: now,
      lastTime: now,
    };
  } else {
    wheelGesture.targetZoom = clampZoom(wheelGesture.targetZoom * Math.exp(-delta * WHEEL_ZOOM_SENSITIVITY));
  }

  wheelGesture.idleAt = now;

  if (!wheelRaf) {
    wheelGesture.lastTime = now;
    wheelRaf = requestAnimationFrame(wheelAnimate);
  }
}, { passive: false });


window.addEventListener('mouseup', () => {
if (!isPanning) return;
isPanning = false;
document.body.classList.remove('panning');
});
img.addEventListener('dragstart', (e) => e.preventDefault());


// Toggle pan mode with "P"
window.addEventListener('keydown', (e) => {
  if (e.code === 'KeyP' && !e.repeat && !e.altKey && !e.ctrlKey && !e.metaKey) {
    if (isEditableTarget(e.target)) return;
    e.preventDefault();
    togglePanMode();
  }
});





  async function loadJSON(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error('Failed to load ' + url + ': ' + res.status);
    const data = await res.json();

    pdfW = Number(data.width) || null;
    pdfH = Number(data.height) || null;
    rotation = ((Number(data.rotation) || 0) % 360 + 360) % 360;

    const items = Array.isArray(data.words) ? data.words : [];

    words = [];
    overlay.innerHTML = '';
    let maxX = 0, maxY = 0;

    const PUNCT_END_SET = new Set(['.', ',', ';', ':', '!', '?', ')']);

    for (let idx = 0; idx < items.length; idx++) {
      const node = items[idx];
      let { xMin, yMin, xMax, yMax, text: rawText } = node;
      if (rawText == null) continue;
      rawText = String(rawText).trim();
      if (!rawText) continue;

      const lastChar = rawText.charAt(rawText.length - 1);
      const isHyphenOnly = rawText === '-' || rawText === '–' || rawText === '—';
      const addSpace = !(PUNCT_END_SET.has(lastChar) || isHyphenOnly);
      const text = rawText.toLowerCase();

      if (isFinite(pdfW) && isFinite(pdfH)) {
        xMin = Math.max(0, Math.min(pdfW, Number(xMin)));
        xMax = Math.max(0, Math.min(pdfW, Number(xMax)));
        yMin = Math.max(0, Math.min(pdfH, Number(yMin)));
        yMax = Math.max(0, Math.min(pdfH, Number(yMax)));
      } else {
        xMin = Number(xMin); xMax = Number(xMax); yMin = Number(yMin); yMax = Number(yMax);
      }

      const el = document.createElement('div');
      el.className = 'word';
      el.title = rawText;
      el.textContent = rawText + (addSpace ? '\u00A0' : '');
      overlay.appendChild(el);

      words.push({ xMin, yMin, xMax, yMax, text, el, sep: addSpace ? ' ' : '' });
      if (isFinite(xMax)) maxX = Math.max(maxX, xMax);
      if (isFinite(yMax)) maxY = Math.max(maxY, yMax);
    }

    if (!pdfW || !pdfH) {
      pdfW = maxX || 612;
      pdfH = maxY || 792;
    }

    // Build phrase-search index from words array
    searchJoined = '';
    searchMap = [];
    for (let i = 0; i < words.length; i++) {
      const w = words[i];
      const chunk = w.text;
      for (let c = 0; c < chunk.length; c++) {
        searchJoined += chunk[c];
        searchMap.push({ wordIndex: i, isSpace: false });
      }
      if (w.sep) {
        searchJoined += ' ';
        searchMap.push({ wordIndex: i, isSpace: true });
      }
    }

    layout();
  }

  function getParam(name) {
    const usp = new URLSearchParams(location.search);
    return usp.get(name) || '';
  }


  function changePage(offset) {
    if (!hasDocData) return;
    const targetId = offset < 0 ? docData.prevId : docData.nextId;
    if (!targetId) return;

    const usp = new URLSearchParams(location.search);
    usp.set('page', String(targetId));
    location.search = '?' + usp.toString();
  }

if (!docData || !docData.prevId) {
  prevBtn.disabled = true;
} else {
  prevBtn.addEventListener('click', () => changePage(-1));
}

if (!docData || !docData.nextId) {
  nextBtn.disabled = true;
} else {
  nextBtn.addEventListener('click', () => changePage(1));
}


window.addEventListener('keydown', (e) => {
  if (['INPUT','TEXTAREA'].includes((e.target.tagName || ''))) return;
  if (e.key === 'ArrowLeft') { e.preventDefault(); changePage(-1); }
  if (e.key === 'ArrowRight') { e.preventDefault(); changePage(1); }
});


  // === Events ===
  img.addEventListener('load', () => { if (fitMode === 'fit') setZoom(computeFitZoom()); updateContainerSize(); layout(); });
  window.addEventListener('resize', () => {
    if (fitMode === 'fit') setZoom(computeFitZoom());
    layout();
    updateHeaderOffset();
  });
  btnFind.addEventListener('click', () => highlightAny(input.value));
  btnClear.addEventListener('click', () => { input.value = ''; highlightAny(''); });
  if (chkDebug) {
    chkDebug.addEventListener('change', () => {
      document.body.classList.toggle('debug', chkDebug.checked);
    });
  }
  if (chkOrigin) {
    originTopLeft = chkOrigin.checked;
    chkOrigin.addEventListener('change', () => { originTopLeft = chkOrigin.checked; layout(); });
  }

  // Zoom controls
  zoomRange.addEventListener('input', () => {
    fitMode = 'custom';
    stopWheelAnimation();
    setZoom(Number(zoomRange.value) / 100);
  });
  zoomInBtn.addEventListener('click', () => {
    fitMode = 'custom';
    stopWheelAnimation();
    setZoom(zoom + 0.1);
  });
  zoomOutBtn.addEventListener('click', () => {
    fitMode = 'custom';
    stopWheelAnimation();
    setZoom(zoom - 0.1);
  });
  zoomFitBtn.addEventListener('click', fitToWidth);

  // Keyboard shortcuts: Ctrl/Cmd +/-, Ctrl/Cmd 0
  window.addEventListener('keydown', (e) => {
    const plus = (e.key === '=' || e.key === '+');
    const minus = (e.key === '-' || e.key === '_');
    const zero = (e.key === '0');
    if ((e.ctrlKey || e.metaKey) && (plus || minus || zero)) {
      e.preventDefault();
      stopWheelAnimation();
      if (plus) setZoom(zoom + 0.1);
      else if (minus) setZoom(zoom - 0.1);
      else if (zero) setZoom(1);
    }
  });
// encode each path segment; keep slashes
const encPath = s => s.split('/').map(encodeURIComponent).join('/');
// pad to 4 digits
const pad4 = n => String(n).padStart(4, '0');
  // === Init ===
  (async function init() {
    if (!hasDocData) {
      if (docData && docData.error) {
        console.error(docData.error);
        showToast(docData.error);
      }
      prevBtn.disabled = true;
      nextBtn.disabled = true;
      return;
    }

    const base = docData.pageBase;

// ✅ parse to int, then pad — converts page-00012 -> page-0012
const fixedBase = base.replace(/page-(\d+)$/, (_, num) =>
  'page-' + pad4(parseInt(num, 10) || 0)
);

const imageFile = encPath(fixedBase) + '.webp';
const textBase  = fixedBase.replace('/pages/', '/text/');
const jsonFile  = encPath(textBase) + '.json';


    img.src = imageFile;
    try {
      await loadJSON(jsonFile);
    } catch (err) {
      console.error(err);
      showToast(err.message);
      return;
    }

    // Support multi-term q: comma-separated
    const rawQ = docData.searchQuery || getParam('q');
    if (rawQ) {
      input.value = rawQ;
      highlightAny(rawQ);
    }

  })();
</script>
</body>
</html>
