(function () {
  'use strict';

  function continueImageImport() {
    var form = document.getElementById('takinta-wc-draft-image-form');
    if (!form || form.getAttribute('data-auto-submit') !== '1') return;
    if (form.getAttribute('data-submitting') === '1') return;

    form.setAttribute('data-submitting', '1');
    window.setTimeout(function () {
      if (!document.documentElement.contains(form)) return;
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        HTMLFormElement.prototype.submit.call(form);
      }
    }, 500);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', continueImageImport);
  } else {
    continueImageImport();
  }
})();
