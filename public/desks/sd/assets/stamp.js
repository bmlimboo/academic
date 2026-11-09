/* SignDesk stamping: preview pages (pdf.js), drag items, write them into the PDF (pdf-lib). */
(function (root) {
  'use strict';

  // Positions are fractions of the page: x, y = top-left corner; w = image width; fs = font size (all relative to page width).
  async function buildStampedPdf(PDFLib, pdfBytes, items, images) {
    const { PDFDocument, StandardFonts, rgb } = PDFLib;
    const pdf = await PDFDocument.load(pdfBytes);
    const font = await pdf.embedFont(StandardFonts.HelveticaBold);
    const emb = {};
    for (const k of Object.keys(images)) {
      emb[k] = images[k].mime === 'image/png' ? await pdf.embedPng(images[k].bytes) : await pdf.embedJpg(images[k].bytes);
    }
    const pages = pdf.getPages();
    for (const it of items) {
      const page = pages[it.page];
      const mb = page.getMediaBox();
      const W = mb.width, H = mb.height;
      if (it.type === 'signature' || it.type === 'seal') {
        const img = emb[it.type];
        const w = it.w * W, h = w * img.height / img.width;
        page.drawImage(img, { x: mb.x + it.x * W, y: mb.y + H - it.y * H - h, width: w, height: h });
      } else {
        const size = it.fs * W;
        page.drawText(it.text, { x: mb.x + it.x * W, y: mb.y + H - it.y * H - size * 0.8, size: size, font: font, color: rgb(0.05, 0.05, 0.05) });
      }
    }
    return await pdf.save();
  }

  if (typeof module !== 'undefined' && module.exports) { module.exports = { buildStampedPdf: buildStampedPdf }; }
  if (typeof document === 'undefined') { return; }

  const el = document.getElementById('stamper');
  if (!el) { return; }
  const $ = function (id) { return document.getElementById(id); };
  const pagesEl = $('pages'), sizeEl = $('size'), selbox = $('selbox'), msgEl = $('msg');
  const dispatchEl = $('dispatch'), passEl = $('password'), applyBtn = $('apply');
  const items = [], pageEls = [];
  let selected = null, nid = 1;

  function normDispatch(v) { return v.replace(/\s+/g, ' ').replace(/\s*\/\s*/g, '/').trim(); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function say(text, kind) { msgEl.hidden = !text; msgEl.textContent = text || ''; msgEl.className = 'flash ' + (kind || 'err'); }
  function textOf(it) { return it.type === 'date' ? el.dataset.date : (normDispatch(dispatchEl.value) || 'Dispatch no.'); }
  function isImg(it) { return it.type === 'signature' || it.type === 'seal'; }

  function layout(it) {
    const n = it.node, W = pageEls[it.page].clientWidth;
    n.style.left = (it.x * 100) + '%';
    n.style.top = (it.y * 100) + '%';
    if (isImg(it)) { n.style.width = (it.w * 100) + '%'; }
    else { n.style.fontSize = (it.fs * W) + 'px'; n.textContent = textOf(it); }
    n.classList.toggle('sel', it === selected);
  }

  function select(it) {
    selected = it;
    items.forEach(layout);
    selbox.hidden = !it;
    if (!it) { return; }
    if (isImg(it)) { sizeEl.min = 5; sizeEl.max = 60; sizeEl.step = 1; sizeEl.value = it.w * 100; }
    else { sizeEl.min = 1; sizeEl.max = 6; sizeEl.step = 0.1; sizeEl.value = it.fs * 100; }
  }

  function startDrag(ev, it) {
    ev.preventDefault(); ev.stopPropagation();
    select(it);
    const n = it.node, wrap = pageEls[it.page];
    const sx = ev.clientX, sy = ev.clientY, ox = it.x, oy = it.y;
    n.setPointerCapture(ev.pointerId);
    function move(e) {
      const r = wrap.getBoundingClientRect();
      it.x = clamp(ox + (e.clientX - sx) / r.width, 0, Math.max(0, 1 - n.offsetWidth / r.width));
      it.y = clamp(oy + (e.clientY - sy) / r.height, 0, Math.max(0, 1 - n.offsetHeight / r.height));
      layout(it);
    }
    function up() { n.removeEventListener('pointermove', move); n.removeEventListener('pointerup', up); n.removeEventListener('pointercancel', up); }
    n.addEventListener('pointermove', move); n.addEventListener('pointerup', up); n.addEventListener('pointercancel', up);
  }

  function currentPage() {
    const mid = window.innerHeight / 2; let best = 0, bd = Infinity;
    pageEls.forEach(function (p, i) {
      const r = p.getBoundingClientRect();
      const d = (r.top <= mid && r.bottom >= mid) ? 0 : Math.min(Math.abs(r.top - mid), Math.abs(r.bottom - mid));
      if (d < bd) { bd = d; best = i; }
    });
    return best;
  }

  function addItem(type) {
    if (!pageEls.length) { return; }
    const p = currentPage(), r = pageEls[p].getBoundingClientRect();
    const k = items.length % 6;
    const it = { id: nid++, type: type, page: p, x: 0.22 + 0.07 * k, y: clamp((window.innerHeight / 2 - r.top) / r.height - 0.04 + 0.04 * k, 0.03, 0.88) };
    if (type === 'signature') { it.w = 0.22; } else if (type === 'seal') { it.w = 0.18; } else { it.fs = 0.022; }
    let n;
    if (isImg(it)) { n = new Image(); n.src = el.dataset[type]; n.draggable = false; n.alt = type; }
    else { n = document.createElement('div'); }
    n.className = 'si ' + type;
    it.node = n;
    pageEls[p].appendChild(n);
    n.addEventListener('pointerdown', function (ev) { startDrag(ev, it); });
    items.push(it);
    select(it);
  }

  sizeEl.addEventListener('input', function () {
    if (!selected) { return; }
    const v = parseFloat(sizeEl.value) / 100;
    if (isImg(selected)) { selected.w = v; } else { selected.fs = v; }
    layout(selected);
  });
  $('remove').addEventListener('click', function () {
    if (!selected) { return; }
    selected.node.remove();
    items.splice(items.indexOf(selected), 1);
    select(null);
  });
  dispatchEl.addEventListener('input', function () { items.forEach(function (it) { if (it.type === 'dispatch') { layout(it); } }); });
  document.querySelectorAll('[data-add]').forEach(function (b) { b.addEventListener('click', function () { addItem(b.dataset.add); }); });
  pagesEl.addEventListener('pointerdown', function () { select(null); });

  async function renderPages() {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js';
    const doc = await pdfjsLib.getDocument(el.dataset.pdf).promise;
    pagesEl.innerHTML = '';
    const cssW = Math.min(pagesEl.clientWidth || 800, 820), dpr = window.devicePixelRatio || 1;
    for (let n = 1; n <= doc.numPages; n++) {
      const page = await doc.getPage(n);
      const v1 = page.getViewport({ scale: 1 }), scale = cssW / v1.width, vp = page.getViewport({ scale: scale * dpr });
      const wrap = document.createElement('div');
      wrap.className = 'pg';
      wrap.style.width = cssW + 'px';
      wrap.style.height = (v1.height * scale) + 'px';
      const canvas = document.createElement('canvas');
      canvas.width = vp.width; canvas.height = vp.height;
      wrap.appendChild(canvas);
      const tag = document.createElement('span'); tag.className = 'pgnum'; tag.textContent = 'Page ' + n + ' of ' + doc.numPages;
      wrap.appendChild(tag);
      pagesEl.appendChild(wrap); pageEls.push(wrap);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }
  }

  async function apply() {
    say('');
    if (!items.length) { return say('Add at least one item (signature, seal, date or dispatch number) first.'); }
    if (!passEl.value) { return say('Enter your password to confirm.'); }
    const disp = normDispatch(dispatchEl.value), hasDispItem = items.some(function (i) { return i.type === 'dispatch'; });
    if (hasDispItem && !disp) { return say('Type the dispatch number, or remove the dispatch item.'); }
    if (disp && !hasDispItem && !confirm('The dispatch number is not placed on the document. Save anyway?')) { return; }
    applyBtn.disabled = true; say('Creating the signed PDF…', 'ok');
    try {
      const pdfBytes = new Uint8Array(await (await fetch(el.dataset.pdf, { credentials: 'same-origin' })).arrayBuffer());
      const images = {};
      for (const t of ['signature', 'seal']) {
        if (items.some(function (i) { return i.type === t; })) {
          const r = await fetch(el.dataset[t], { credentials: 'same-origin' });
          images[t] = { bytes: new Uint8Array(await r.arrayBuffer()), mime: r.headers.get('content-type') };
        }
      }
      const plain = items.map(function (i) { return { type: i.type, page: i.page, x: i.x, y: i.y, w: i.w, fs: i.fs, text: i.type === 'date' ? el.dataset.date : disp }; });
      let out;
      try { out = await buildStampedPdf(PDFLib, pdfBytes, plain, images); }
      catch (e) { throw new Error('Could not write onto the PDF: ' + e.message); }
      const fd = new FormData();
      fd.append('csrf', el.dataset.csrf); fd.append('id', el.dataset.id);
      fd.append('password', passEl.value); fd.append('dispatch_no', disp);
      fd.append('summary', items.map(function (i) { return i.type + ' p' + (i.page + 1); }).join(', '));
      fd.append('signed', new Blob([out], { type: 'application/pdf' }), 'signed.pdf');
      say('Saving…', 'ok');
      const res = await fetch('stamp_save.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.ok) { window.location.href = data.redirect; return; }
      say(data.error || 'Could not save the signed copy.');
    } catch (e) { say(e.message || 'Something went wrong.'); }
    applyBtn.disabled = false;
  }
  applyBtn.addEventListener('click', apply);

  renderPages().catch(function (e) { pagesEl.innerHTML = '<p class="flash err">Could not show the preview: ' + (e && e.message ? e.message : e) + '</p>'; });
})(typeof window !== 'undefined' ? window : globalThis);
