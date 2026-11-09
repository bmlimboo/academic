document.querySelectorAll('[data-confirm]').forEach(function (b) {
  b.addEventListener('click', function (ev) { if (!confirm(b.dataset.confirm)) ev.preventDefault(); });
});
document.querySelectorAll('input[type=file][data-max]').forEach(function (inp) {
  inp.addEventListener('change', function () {
    var f = inp.files[0], max = parseInt(inp.dataset.max, 10);
    if (f && f.size > max) { alert('That file is larger than ' + Math.round(max / 1048576) + ' MB.'); inp.value = ''; }
  });
});

// Every action and upload opens in its own window; the main page refreshes after saving.
document.querySelectorAll('a.popup-link').forEach(function (a) {
  a.addEventListener('click', function (ev) {
    var w = window.open(a.href, window.name === 'rtd_action' ? 'rtd_form' : 'rtd_action', 'width=1040,height=800,scrollbars=yes,resizable=yes');
    if (w) { ev.preventDefault(); w.focus(); }
  });
});
if (document.body.dataset.saved === '1' && window.opener && !window.opener.closed) {
  try { window.opener.location.reload(); } catch (e) {}
}
document.querySelectorAll('a[data-alert]').forEach(function (a) {
  a.addEventListener('click', function (ev) { ev.preventDefault(); alert(a.dataset.alert); });
});

// BIG-COMMENT-BOXES: auto-grow comment boxes so all typed text is visible
(function () {
  function grow(t) {
    t.style.height = 'auto';
    t.style.height = Math.min(t.scrollHeight + 2, Math.round(window.innerHeight * 0.7)) + 'px';
  }
  document.querySelectorAll('textarea').forEach(function (t) {
    t.addEventListener('input', function () { grow(t); });
    grow(t);
  });
  window.addEventListener('resize', function () {
    document.querySelectorAll('textarea').forEach(grow);
  });
})();

// FULLROW-COMMENTS: put every comment box on its own row spanning the whole table
(function () {
  function grow(t) {
    t.style.height = 'auto';
    var h = Math.max(t.scrollHeight + 2, 170);
    t.style.height = Math.min(h, Math.round(window.innerHeight * 0.7)) + 'px';
  }
  var n = 0;
  document.querySelectorAll('table textarea.autogrow').forEach(function (t) {
    var form = t.closest('form'), tr = t.closest('tr'), table = t.closest('table');
    if (!form || !tr || !table) return;
    if (!form.id) form.id = 'cform' + (++n);
    t.setAttribute('form', form.id);            // still submits with its own form
    var cols = table.querySelectorAll('thead th').length || tr.children.length;
    var row = document.createElement('tr'), cell = document.createElement('td');
    row.className = 'commentrow'; cell.colSpan = cols;
    cell.appendChild(t); row.appendChild(cell);
    tr.classList.add('hascomment');
    tr.parentNode.insertBefore(row, tr.nextSibling);
    t.addEventListener('input', function () { grow(t); });
    grow(t);
  });
  window.addEventListener('resize', function () {
    document.querySelectorAll('tr.commentrow textarea').forEach(grow);
  });
})();

// EVAL-RETURN: live word counter (max words) and auto-grow for evaluator comment boxes
document.querySelectorAll('textarea[data-maxwords]').forEach(function (t) {
  var max = parseInt(t.dataset.maxwords, 10), form = t.closest('form');
  var wc = form ? form.querySelector('.wc') : null;
  function count() { var s = t.value.trim(); return s ? s.split(/\s+/).length : 0; }
  function update() {
    var n = count();
    if (wc) { wc.textContent = n + ' / ' + max + ' words'; wc.classList.toggle('over', n > max); }
    t.style.height = 'auto';
    t.style.height = Math.min(Math.max(t.scrollHeight + 2, 220), Math.round(window.innerHeight * 0.7)) + 'px';
  }
  t.addEventListener('input', update); update();
  if (form) form.addEventListener('submit', function (ev) {
    if (count() > max) { ev.preventDefault(); alert('Please keep the comment within ' + max + ' words.'); }
  });
});

// ELEGANT-REPORTS: soften empty cells ("–") in report tables
document.querySelectorAll('.rxtable td, .markstable td').forEach(function (td) {
  var t = td.textContent.trim();
  if (t === '–' || t === '-' || t === '—') td.classList.add('dash');
});
