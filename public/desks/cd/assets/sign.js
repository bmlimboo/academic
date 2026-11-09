// DAA signing screen: shows the release letter, lets the DAA drag the signature and seal into place,
// stamps them into the PDF in the browser (pdf-lib) and uploads the signed copy.
(function () {
  var C = window.SIGN;
  if (!C) return;
  var $ = function (id) { return document.getElementById(id); };
  var stage = $('stage'), canvas = $('pdfcanvas'), msg = $('signmsg'), applyBtn = $('apply');
  var st = { bytes: null, pdf: null, page: 1, count: 1, cssW: 0, cssH: 0, rotated: false, sig: null, seal: null };
  var sigBox = null, sealBox = null;

  function say(text, bad) {
    msg.textContent = text;
    msg.style.background = bad ? '#fde0e0' : '#eef2fb';
    msg.style.color = bad ? '#a32020' : '';
    msg.style.display = text ? '' : 'none';
  }

  // Tries each URL in turn (local copy first, then the CDN). Resolves with the URL that worked.
  function loadScript(urls) {
    return new Promise(function (resolve, reject) {
      var i = 0;
      (function next() {
        if (i >= urls.length) { reject(new Error('Could not load a required library. Check the internet connection, or put the library files in assets/vendor/.')); return; }
        var url = urls[i++], s = document.createElement('script');
        s.src = url;
        s.onload = function () { resolve(url); };
        s.onerror = function () { s.remove(); next(); };
        document.head.appendChild(s);
      })();
    });
  }

  function getBytes(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('Could not load ' + url);
      return r.arrayBuffer();
    });
  }

  function makeDraggable(el) {
    var sx = 0, sy = 0, ox = 0, oy = 0, on = false;
    el.addEventListener('pointerdown', function (e) {
      on = true; sx = e.clientX; sy = e.clientY; ox = el.offsetLeft; oy = el.offsetTop;
      el.setPointerCapture(e.pointerId); e.preventDefault();
    });
    el.addEventListener('pointermove', function (e) {
      if (!on) return;
      var x = Math.max(0, Math.min(ox + e.clientX - sx, stage.clientWidth - el.offsetWidth));
      var y = Math.max(0, Math.min(oy + e.clientY - sy, stage.clientHeight - el.offsetHeight));
      el.style.left = x + 'px'; el.style.top = y + 'px';
    });
    var stop = function () { on = false; };
    el.addEventListener('pointerup', stop);
    el.addEventListener('pointercancel', stop);
  }

  function makeStamp(url, kind, width) {
    var box = document.createElement('div');
    box.className = 'stamp';
    var img = document.createElement('img');
    img.src = url; img.alt = kind; img.draggable = false;
    img.style.width = width + 'px';
    box.appendChild(img);
    stage.appendChild(box);
    makeDraggable(box);
    return box;
  }

  function lineText() {
    return [C.name, C.designation, 'Approved ' + C.date].map(function (t) { return String(t).replace(/[^\x20-\x7E]/g, '?'); });
  }

  function updateLine() {
    if (!sigBox) return;
    var el = sigBox.querySelector('.line');
    if (!$('useLine').checked) { if (el) el.remove(); return; }
    if (!el) { el = document.createElement('div'); el.className = 'line'; sigBox.appendChild(el); }
    el.style.fontSize = (8 * st.cssW / (st.pdfW || st.cssW)) + 'px';
    el.innerHTML = '';
    lineText().forEach(function (t) { var d = document.createElement('div'); d.textContent = t; el.appendChild(d); });
  }

  function place(box, rightFrac, bottomFrac) {
    box.style.left = Math.max(0, st.cssW * rightFrac - box.offsetWidth) + 'px';
    box.style.top = Math.max(0, st.cssH * bottomFrac - box.offsetHeight) + 'px';
  }

  function renderPage(n) {
    return st.pdf.getPage(n).then(function (page) {
      st.rotated = (page.rotate % 360) !== 0;
      var base = page.getViewport({ scale: 1 });
      st.pdfW = base.width;
      var maxW = Math.min(stage.parentElement.clientWidth - 8, 820);
      var scale = maxW / base.width, dpr = window.devicePixelRatio || 1;
      var vp = page.getViewport({ scale: scale });
      canvas.width = Math.floor(vp.width * dpr); canvas.height = Math.floor(vp.height * dpr);
      canvas.style.width = vp.width + 'px'; canvas.style.height = vp.height + 'px';
      stage.style.width = vp.width + 'px'; stage.style.height = vp.height + 'px';
      st.cssW = vp.width; st.cssH = vp.height;
      return page.render({ canvasContext: canvas.getContext('2d'), viewport: page.getViewport({ scale: scale * dpr }) }).promise;
    }).then(function () {
      $('pageno').textContent = st.page; $('pagecount').textContent = st.count;
      $('prev').disabled = st.page <= 1; $('next').disabled = st.page >= st.count;
      updateLine();
      if (st.rotated) { say('This page is rotated, so it cannot be stamped here. Use the upload option below the page.', true); applyBtn.disabled = true; }
      else { say(''); applyBtn.disabled = false; }
    });
  }

  function go(n) {
    if (n < 1 || n > st.count) return;
    st.page = n;
    renderPage(n).then(function () {
      if (sigBox) place(sigBox, 0.9, 0.9);
      if (sealBox) place(sealBox, 0.62, 0.92);
    });
  }

  function toggle(box, on) { if (box) box.style.display = on ? '' : 'none'; }

  function apply() {
    var useSig = $('useSig').checked && sigBox, useSeal = $('useSeal').checked && sealBox;
    if (!useSig && !useSeal) { say('Tick the signature or the seal first.', true); return; }
    if (!confirm('Approve this request and put your ' + (useSig && useSeal ? 'signature and seal' : (useSig ? 'signature' : 'seal')) + ' on the letter?')) return;
    applyBtn.disabled = true; say('Preparing the signed letter...');
    var L = window.PDFLib, doc, page, k, font;
    L.PDFDocument.load(st.bytes, { ignoreEncryption: true }).then(function (d) {
      doc = d; page = doc.getPage(st.page - 1);
      k = page.getSize().width / st.cssW;
      return doc.embedFont(L.StandardFonts.Helvetica);
    }).then(function (f) {
      font = f;
      var H = page.getSize().height, jobs = Promise.resolve();
      function stamp(box, bytes, withText) {
        jobs = jobs.then(function () { return doc.embedPng(bytes); }).then(function (png) {
          var img = box.querySelector('img');
          var w = img.clientWidth * k, h = img.clientHeight * k;
          var x = box.offsetLeft * k, y = H - (box.offsetTop * k) - h;
          page.drawImage(png, { x: x, y: y, width: w, height: h });
          if (withText) {
            lineText().forEach(function (t, i) {
              page.drawText(t, { x: x, y: y - 9 - i * 10, size: 8, font: font, color: L.rgb(0.1, 0.1, 0.1) });
            });
          }
        });
      }
      if (useSig) stamp(sigBox, st.sig, $('useLine').checked);
      if (useSeal) stamp(sealBox, st.seal, false);
      return jobs;
    }).then(function () { return doc.save(); }).then(function (out) {
      say('Saving...');
      var fd = new FormData();
      fd.append('csrf', C.csrf); fd.append('id', C.id); fd.append('ajax', '1');
      fd.append('remarks', $('remarks').value);
      fd.append('signed', new Blob([out], { type: 'application/pdf' }), 'signed.pdf');
      return fetch(C.post, { method: 'POST', body: fd, credentials: 'same-origin' });
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'The server did not accept the signed letter.');
      window.location.href = j.redirect;
    }).catch(function (err) {
      say('Could not finish: ' + err.message, true); applyBtn.disabled = false;
    });
  }

  Promise.all([loadScript(C.libs.pdfjs), loadScript(C.libs.pdflib)]).then(function (urls) {
    var local = urls[0].indexOf('assets/vendor/') === 0;
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = local ? C.libs.worker[0] : C.libs.worker[1];
    return Promise.all([getBytes(C.pdfUrl), C.sigUrl ? getBytes(C.sigUrl) : null, C.sealUrl ? getBytes(C.sealUrl) : null]);
  }).then(function (res) {
    st.bytes = res[0]; st.sig = res[1]; st.seal = res[2];
    // pdf.js may take ownership of the buffer it is given, so it gets its own copy.
    return window.pdfjsLib.getDocument({ data: new Uint8Array(st.bytes.slice(0)) }).promise;
  }).then(function (pdf) {
    st.pdf = pdf; st.count = pdf.numPages; st.page = pdf.numPages;
    return renderPage(st.page);
  }).then(function () {
    if (C.sigUrl) { sigBox = makeStamp(C.sigUrl, 'signature', +$('sigSize').value); }
    if (C.sealUrl) { sealBox = makeStamp(C.sealUrl, 'seal', +$('sealSize').value); }
    // Wait for the images to know their size, then place them near the bottom right.
    var imgs = [].slice.call(stage.querySelectorAll('.stamp img'));
    return Promise.all(imgs.map(function (i) { return i.complete ? 0 : new Promise(function (r) { i.onload = i.onerror = r; }); }));
  }).then(function () {
    if (sigBox) place(sigBox, 0.9, 0.88);
    if (sealBox) place(sealBox, 0.6, 0.92);
    updateLine();
    $('sigSize').addEventListener('input', function () { if (sigBox) sigBox.querySelector('img').style.width = this.value + 'px'; });
    $('sealSize').addEventListener('input', function () { if (sealBox) sealBox.querySelector('img').style.width = this.value + 'px'; });
    $('useSig').addEventListener('change', function () { toggle(sigBox, this.checked); });
    $('useSeal').addEventListener('change', function () { toggle(sealBox, this.checked); });
    $('useLine').addEventListener('change', updateLine);
    $('prev').addEventListener('click', function () { go(st.page - 1); });
    $('next').addEventListener('click', function () { go(st.page + 1); });
    applyBtn.addEventListener('click', apply);
    $('prev').disabled = st.page <= 1; $('next').disabled = st.page >= st.count;
  }).catch(function (err) {
    say(err.message + ' You can still use the upload option below.', true);
  });
})();
