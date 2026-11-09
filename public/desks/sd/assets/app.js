document.querySelectorAll('[data-confirm]').forEach(function (b) {
  b.addEventListener('click', function (ev) { if (!confirm(b.dataset.confirm)) ev.preventDefault(); });
});
document.querySelectorAll('input[data-filename]').forEach(function (inp) {
  inp.addEventListener('change', function () {
    var f = inp.files[0];
    if (f && f.size > 10 * 1024 * 1024) { alert('That file is larger than 10 MB.'); inp.value = ''; }
  });
});
