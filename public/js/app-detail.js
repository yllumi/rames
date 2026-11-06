(function () {
  'use strict';

  function selectDeployKey() {
    var field = document.getElementById('ssh-pubkey-detail');
    if (!field) return null;

    field.focus();
    field.select();
    field.setSelectionRange(0, field.value.length);
    return field;
  }

  function copyWithSelection() {
    selectDeployKey();
    try {
      document.execCommand('copy');
    } catch (error) {
      // Clipboard access may be unavailable in older browsers.
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.closest('[data-copy-detail-key]')) return;

    if (navigator.clipboard && window.isSecureContext) {
      try {
        navigator.clipboard.writeText((document.getElementById('ssh-pubkey-detail') || {}).value || '')
          .catch(copyWithSelection);
        return;
      } catch (error) {
        copyWithSelection();
        return;
      }
    }

    copyWithSelection();
  });
})();
