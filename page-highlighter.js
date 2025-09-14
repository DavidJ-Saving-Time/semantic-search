// page-highlighter.js — highlight overlays that support cross-span matches (no nav, no DOM mutations)
// Option 2: boxes live inside the textLayer so they inherit pdf.js zoom/transform.
(function () {
  // ---- params (query or hash) ----
  function getParam(name) {
    const q = new URLSearchParams(location.search).get(name);
    if (q) return q;
    const h = location.hash.startsWith("#") ? location.hash.slice(1) : location.hash;
    return new URLSearchParams(h.replace(/^\?/, "")).get(name);
  }
  function getTargetPage() {
    const m = location.hash.match(/(?:^|[&#])page=(\d+)/);
    return m ? parseInt(m[1], 10) : (window.PDFViewerApplication ? PDFViewerApplication.page : 1);
  }

  const terms = (getParam("terms") || "").split(",").map(s => s.trim()).filter(Boolean);
  if (!terms.length) return;

  // ligature/NBSP tolerant pattern
  function pat(term) {
    const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    let p = esc(term);
    p = p.replace(/ffi/gi, "(?:ffi|\\uFB03)")
      .replace(/ffl/gi, "(?:ffl|\\uFB04)")
      .replace(/ff/gi, "(?:ff|\\uFB00)")
      .replace(/fi/gi, "(?:fi|\\uFB01)")
      .replace(/fl/gi, "(?:fl|\\uFB02)")
      .replace(/\s+/g, "[\\u00A0\\s]+")
      .replace(/\s+/g, "(?:-?[\\u00A0\\s]+)");
    return p;
  }
  const RX = new RegExp(terms.map(pat).join("|"), "giu");

  // ---- flatten logical text per textLayer ----
  const SPACE = " ";
  function buildFlattened(tl) {
    const walker = document.createTreeWalker(tl, NodeFilter.SHOW_TEXT);
    const nodes = [];
    let n; while ((n = walker.nextNode())) nodes.push(n);

    const segs = [];
    const parts = [];
    let flatLen = 0;

    function isSoftHyphenJoin(prevText, nextText) {
      return /-$/.test(prevText) && /^[a-z\u00C0-\u024F]/i.test(nextText[0]);
    }

    for (let i = 0; i < nodes.length; i++) {
      const node = nodes[i];
      const raw = node.nodeValue || "";
      const norm = raw.replace(/\s+/g, " ");

      if (i > 0) {
        const prevRaw = nodes[i - 1].nodeValue || "";
        if (!isSoftHyphenJoin(prevRaw, raw)) {
          parts.push(SPACE);
          flatLen += 1;
        }
      }

      const start = flatLen;
      parts.push(norm);
      flatLen += norm.length;
      const end = flatLen;

      segs.push({ node, startIdxInFlat: start, endIdxInFlat: end, nodeTextLen: norm.length });
    }

    const flat = parts.join("");

    function snapIndexToDom(idx) {
      for (let i = 0; i < segs.length; i++) {
        const s = segs[i];
        if (idx < s.startIdxInFlat) return { node: s.node, offset: 0 };
        if (idx >= s.startIdxInFlat && idx < s.endIdxInFlat) {
          return { node: s.node, offset: idx - s.startIdxInFlat };
        }
      }
      const last = segs[segs.length - 1];
      return { node: last.node, offset: last.nodeTextLen };
    }

    return { flat, segs, snapIndexToDom };
  }

  // ---- overlay helpers (Option 2: attach to textLayer) ----
  function clearOverlays(tl) {
    tl.querySelectorAll(".myhl-box").forEach(el => el.remove());
  }

  function addBox(tl, textRect, r) {
    const nudge = r.height * 0.12; // simple upward nudge
    const box = document.createElement("div");
    box.className = "myhl-box";
    box.style.position = "absolute";
    box.style.pointerEvents = "none";
    box.style.background = "yellow";
    box.style.opacity = "0.35";

    const left = r.left - textRect.left;
    const top  = r.top  - textRect.top - nudge;
    const width = r.width;
    const height = r.height;

    box.style.left = left + "px";
    box.style.top = top + "px";
    box.style.width = width + "px";
    box.style.height = height + "px";

    tl.appendChild(box); // ← attach inside textLayer so it inherits zoom/transform
  }

  const flattenCache = new WeakMap(); // tl → {flat, segs, snapIndexToDom}

  function highlightPage(n) {
    const pv = PDFViewerApplication.pdfViewer.getPageView(n - 1);
    if (!pv) return;
    const tl = pv?.textLayer?.textLayerDiv || pv.div.querySelector(".textLayer");
    if (!tl) return;

    clearOverlays(tl);

    let flatPack = flattenCache.get(tl);
    if (!flatPack) {
      flatPack = buildFlattened(tl);
      flattenCache.set(tl, flatPack);
    }

    const { flat, snapIndexToDom } = flatPack;
    const textRect = tl.getBoundingClientRect();

    RX.lastIndex = 0;
    let m;
    while ((m = RX.exec(flat))) {
      const startIdx = m.index;
      const endIdx = m.index + m[0].length;

      const { node: startNode, offset: startOff } = snapIndexToDom(startIdx);
      const { node: endNode, offset: endOff } = snapIndexToDom(endIdx);

      const range = document.createRange();
      range.setStart(startNode, startOff);
      range.setEnd(endNode, endOff);

      const rects = range.getClientRects();
      for (const r of rects) addBox(tl, textRect, r);

      range.detach?.();
      if (RX.lastIndex === m.index) RX.lastIndex++;
    }
  }

  function run() {
    const bus = PDFViewerApplication.eventBus;
    const on  = bus.on ? (e,h)=>bus.on(e,h) : (e,h)=>bus._on(e,h);
    const off = bus.off ? (e,h)=>bus.off(e,h) : (e,h)=>bus._off(e,h);
    const target = getTargetPage();

    const firstPaint = evt => {
      if (evt.pageNumber === target) {
        const pv = PDFViewerApplication.pdfViewer.getPageView(target - 1);
        const tl = pv?.textLayer?.textLayerDiv || pv?.div?.querySelector(".textLayer");
        if (tl) flattenCache.delete(tl);
        highlightPage(target);
        off("textlayerrendered", firstPaint);
      }
    };
    on("textlayerrendered", firstPaint);
    highlightPage(target);

    const rehighlightAfterRender = () => {
      const handler = evt => {
        if (evt.pageNumber === target) {
          const pv = PDFViewerApplication.pdfViewer.getPageView(target - 1);
          const tl = pv?.textLayer?.textLayerDiv || pv?.div?.querySelector(".textLayer");
          if (tl) flattenCache.delete(tl);
          highlightPage(target);
          off("textlayerrendered", handler);
        }
      };
      on("textlayerrendered", handler);
    };

    on("scalechanging", rehighlightAfterRender);
    on("scalechanged",  rehighlightAfterRender);
    on("rotationchanging", rehighlightAfterRender);
  }

  // ---- bootstrap ----
  (function waitForApp() {
    if (window.PDFViewerApplication && PDFViewerApplication.eventBus) {
      const ready = PDFViewerApplication.initializationPromise || Promise.resolve();
      ready.then(() => {
        const bus = PDFViewerApplication.eventBus;
        const on = bus.on ? (e,h)=>bus.on(e,h) : (e,h)=>bus._on(e,h);
        on("pagesinit", run);
      });
    } else {
      setTimeout(waitForApp, 50);
    }
  })();
})();