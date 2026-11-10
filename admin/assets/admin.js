/* Librescape admin – small helpers on top of server-rendered pages. No framework. */
'use strict';

(function () {
    // Confirm dangerous actions: <button data-confirm="Really delete?">
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.dataset.confirm)) {
            e.preventDefault();
        }
    });
})();
