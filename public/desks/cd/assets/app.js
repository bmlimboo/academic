document.querySelectorAll('[data-confirm]').forEach(function (b) {
  b.addEventListener('click', function (ev) { if (!confirm(b.dataset.confirm)) ev.preventDefault(); });
});
document.querySelectorAll('input[type=file][data-max]').forEach(function (inp) {
  inp.addEventListener('change', function () {
    var max = parseInt(inp.dataset.max, 10);
    for (var i = 0; i < inp.files.length; i++) {
      if (inp.files[i].size > max) {
        alert('"' + inp.files[i].name + '" is larger than ' + Math.round(max / 1048576) + ' MB.');
        inp.value = '';
        return;
      }
    }
  });
});
// Request form: show the instructions of a certificate only while it is ticked.
document.querySelectorAll('.typeitem').forEach(function (item) {
  var box = item.querySelector('input[type=checkbox]'), info = item.querySelector('.typeinfo');
  if (!box || !info) return;
  function sync() {
    info.style.display = box.checked ? '' : 'none';
    var txt = info.querySelector('input[name^="other_text"]');
    if (txt) txt.required = box.checked;
  }
  box.addEventListener('change', sync);
  sync();
});
