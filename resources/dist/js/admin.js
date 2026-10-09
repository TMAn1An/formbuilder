/* Builder admin helpers: "Copy public link" buttons ([data-fba-copy]). */
(function () {
  'use strict';

  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'absolute';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(area);
    return ok;
  }

  function flash(button, text) {
    var original = button.dataset.fbaLabel || button.textContent;
    button.dataset.fbaLabel = original;
    button.textContent = text;
    window.setTimeout(function () { button.textContent = original; }, 1600);
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest ? e.target.closest('[data-fba-copy]') : null;
    if (!button) return;
    e.preventDefault();
    var url = button.getAttribute('data-fba-copy');

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(function () { flash(button, 'Copied!'); }, function () {
        flash(button, fallbackCopy(url) ? 'Copied!' : 'Copy failed');
      });
    } else {
      flash(button, fallbackCopy(url) ? 'Copied!' : 'Copy failed');
    }
  });
})();
