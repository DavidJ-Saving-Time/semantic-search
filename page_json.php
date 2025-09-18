<?php
ini_set('display_errors', '0');

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function encode_path(string $path): string
{
    $trimmed = trim($path, '/');
    if ($trimmed === '') {
        return '';
    }
    $parts = explode('/', $trimmed);
    $encoded = [];
    foreach ($parts as $part) {
        $encoded[] = rawurlencode($part);
    }
    return '/' . implode('/', $encoded);
}

$PGHOST = getenv('PGHOST') ?: 'localhost';
$PGPORT = getenv('PGPORT') ?: '5432';
$PGDATABASE = getenv('PGDATABASE') ?: 'journals';
$PGUSER = getenv('PGUSER') ?: 'journal_user';
$PGPASSWORD = getenv('PGPASSWORD') ?: '';

$pageParam = $_GET['page'] ?? '';
$pageId = null;
$error = '';

if ($pageParam === '' || !preg_match('/^\d+$/', $pageParam)) {
    $error = 'Invalid or missing page id.';
} else {
    $pageId = (int)$pageParam;
}

$doc = null;
if ($error === '') {
    $connStr = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s",
        $PGHOST,
        $PGPORT,
        $PGDATABASE,
        $PGUSER,
        $PGPASSWORD
    );

    $pgconn = @pg_connect($connStr);
    if ($pgconn === false) {
        $error = 'Database connection failed.';
    } else {
        $sql = <<<SQL
WITH s AS (
  SELECT id, page
  FROM docs
  WHERE issue = (SELECT issue FROM docs WHERE id = $1)
    AND page IS NOT NULL
  ORDER BY page ASC, id ASC
),
r AS (
  SELECT
    id,
    LAG(id)  OVER (ORDER BY page, id) AS prev_id,
    LEAD(id) OVER (ORDER BY page, id) AS next_id
  FROM s
)
SELECT d.id,
       d.source_file,
       d.meta->>'journal' AS journal,
       d.issue,
       d.page,
       r.prev_id,
       r.next_id
FROM docs d
JOIN r ON r.id = d.id
WHERE d.id = $1; 
SQL;

        $res = pg_query_params($pgconn, $sql, [$pageId]);
        if ($res === false) {
            $error = 'Failed to load page metadata.';
        } else {
            $doc = pg_fetch_assoc($res) ?: null;
            if (!$doc) {
                $error = 'Page not found.';
            }
        }

        pg_close($pgconn);
    }
}

$pageMeta = null;
$pageLabel = $error ?: 'Loading…';
$prevDisabled = true;
$nextDisabled = true;

if ($error === '' && $doc) {
    $journal = $doc['journal'] ?? '';
    $issue = $doc['issue'] ?? '';
    $sourceFile = $doc['source_file'] ?? '';
    $pageNumber = null;

    if (isset($doc['page']) && $doc['page'] !== null && $doc['page'] !== '') {
        $pageNumber = (int)$doc['page'];
    } elseif (isset($doc['first_page']) && $doc['first_page'] !== null && $doc['first_page'] !== '') {
        $pageNumber = (int)$doc['first_page'];
    }

    $pad = null;
    if ($pageNumber !== null && $pageNumber > 0) {
        $pad = str_pad((string)$pageNumber, 4, '0', STR_PAD_LEFT);
    }

    $imageRaw = null;
    $jsonRaw = null;

    if ($journal !== '' && $issue !== '' && $pad !== null) {
        $jsonRaw = '/' . $journal . '/' . $issue . '/text/page-' . $pad . '.json';
        $imageRaw = '/' . $journal . '/' . $issue . '/pages/page-' . $pad . '.webp';
    }

    if ($jsonRaw === null && $sourceFile !== '') {
        $jsonRaw = '/' . ltrim($sourceFile, '/');
    }

    if ($imageRaw === null && $jsonRaw !== null) {
        if (preg_match('/\/text\/(page-[^\/]+)\.json$/', $jsonRaw)) {
            $imageRaw = preg_replace('/\/text\/(page-[^\/]+)\.json$/', '/pages/$1.webp', $jsonRaw);
        }
    }

    $jsonPath = $jsonRaw !== null ? encode_path($jsonRaw) : null;
    $imagePath = $imageRaw !== null ? encode_path($imageRaw) : null;

    if ($imagePath === null || $jsonPath === null) {
        $error = 'Page assets missing.';
    } else {
        $prevId = isset($doc['prev_id']) && $doc['prev_id'] !== null ? (int)$doc['prev_id'] : null;
        $nextId = isset($doc['next_id']) && $doc['next_id'] !== null ? (int)$doc['next_id'] : null;

        $pageLabel = $pad !== null ? 'page-' . $pad : ('ID ' . $pageId);
        $prevDisabled = $prevId === null;
        $nextDisabled = $nextId === null;

        $pageMeta = [
            'pageId' => $pageId,
            'pageLabel' => $pageLabel,
            'imagePath' => $imagePath,
            'jsonPath' => $jsonPath,
            'prevId' => $prevId,
            'nextId' => $nextId,
            'issue' => $issue !== '' ? $issue : null,
            'journal' => $journal !== '' ? $journal : null,
        ];
    }
}

$statusCode = 200;
if ($error !== '' && $pageMeta === null) {
    if ($error === 'Invalid or missing page id.') {
        $statusCode = 400;
    } elseif ($error === 'Page not found.') {
        $statusCode = 404;
    } else {
        $statusCode = 500;
    }
    http_response_code($statusCode);
    $pageLabel = $error;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Page Image + Text Overlay (JSON + Zoom)</title>
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
  </style>
</head>
<body<?= $error !== '' ? ' data-error="' . h($error) . '"' : '' ?>>
  <header>
    <input id="q" type="search" placeholder="Find: 'the bay,my house in'" autocomplete="off" />
    <button id="btnFind" title="Find">Find</button>
    <button id="btnClear" title="Clear">Clear</button>
    <label id="lblDebug" title="Toggle debug outlines"><input id="chkDebug" type="checkbox" /> Debug</label>


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

  <div id="toast" class="toast" hidden>0 matches</div>

  <footer>
  <button id="prevPage"<?= $prevDisabled ? ' disabled' : '' ?>>⟨ Prev</button>
  <span id="pageLabel"><?=h($pageLabel)?></span>
  <button id="nextPage"<?= $nextDisabled ? ' disabled' : '' ?>>Next ⟩</button>
</footer>

  <script id="pageData" type="application/json"><?= $pageMeta ? json_encode($pageMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null' ?></script>
  <script>
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
  const prevBtn = document.getElementById('prevPage');
  const nextBtn = document.getElementById('nextPage');
  const pageLabelEl = document.getElementById('pageLabel');
  const pageDataEl = document.getElementById('pageData');
  let pageData = null;
  try {
    pageData = pageDataEl ? JSON.parse(pageDataEl.textContent) : null;
  } catch (err) {
    console.error('Failed to parse page metadata', err);
  }
  const pageError = document.body.getAttribute('data-error') || '';

  if (pageError && toast) {
    toast.textContent = pageError;
    toast.hidden = false;
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


  function goToPageId(targetId) {
    if (!targetId) return;
    const usp = new URLSearchParams(location.search);
    usp.set('page', targetId);
    location.search = '?' + usp.toString();
  }

  function updateNavButtons() {
    if (prevBtn) prevBtn.disabled = !(pageData && pageData.prevId);
    if (nextBtn) nextBtn.disabled = !(pageData && pageData.nextId);
  }

  if (pageLabelEl) {
    if (pageData && pageData.pageLabel) {
      pageLabelEl.textContent = pageData.pageLabel;
    } else if (pageError) {
      pageLabelEl.textContent = pageError;
    }
  }

  updateNavButtons();

  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      if (pageData && pageData.prevId) goToPageId(pageData.prevId);
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      if (pageData && pageData.nextId) goToPageId(pageData.nextId);
    });
  }

  window.addEventListener('keydown', (e) => {
    if (['INPUT','TEXTAREA'].includes((e.target.tagName || ''))) return;
    if (!pageData) return;
    if (e.key === 'ArrowLeft' && pageData.prevId) { e.preventDefault(); goToPageId(pageData.prevId); }
    if (e.key === 'ArrowRight' && pageData.nextId) { e.preventDefault(); goToPageId(pageData.nextId); }
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
  // === Init ===
  (async function init() {
    if (!pageData || !pageData.imagePath || !pageData.jsonPath) {
      if (pageError) {
        console.error(pageError);
        if (pageLabelEl) pageLabelEl.textContent = pageError;
      }
      return;
    }

    img.src = pageData.imagePath;
    try {
      await loadJSON(pageData.jsonPath);
    } catch (err) {
      console.error(err);
      alert(err.message);
      return;
    }

    // Support multi-term q: comma-separated
    const rawQ = getParam('q');
    if (rawQ) {
      input.value = rawQ;
      highlightAny(rawQ);
    }

  })();
</script>
</body>
</html>
