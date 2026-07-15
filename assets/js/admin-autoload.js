(function () {
    'use strict';

    window.tsootcAlToggle = function () {
        var body = document.getElementById('tso-al-body');
        var arrow = document.getElementById('tso-al-arrow');
        if (!body) {
            return;
        }
        body.classList.toggle('open');
        if (arrow) {
            arrow.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
        }
    };

    window.tsootcAlGrpToggle = function (id) {
        var el = document.getElementById(id);
        var ar = document.getElementById(id + '-arrow');
        if (!el) {
            return;
        }
        el.classList.toggle('open');
        if (ar) {
            ar.textContent = el.classList.contains('open') ? '\u25bc' : '\u25b6';
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.querySelector('.tso-al-panel[data-tso-al-open="1"]');
        if (!panel) {
            return;
        }
        var body = document.getElementById('tso-al-body');
        var arrow = document.getElementById('tso-al-arrow');
        if (body) {
            body.classList.add('open');
        }
        if (arrow) {
            arrow.style.transform = 'rotate(180deg)';
        }
    });
})();
