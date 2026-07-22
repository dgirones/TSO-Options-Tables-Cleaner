(function () {
    'use strict';

    var cfg = window.tsootcAdminConfig || {};
    var backupCfg = cfg.backup || {};

    var form = document.getElementById('tso-backup-bulk-form');
    var selectAll = document.getElementById('tso-backup-select-all');
    var bulkBtn = document.getElementById('tso-backup-bulk-delete');
    var countSpan = document.getElementById('tso-backup-selected-count');

    if (!form || !selectAll || !bulkBtn || !countSpan) {
        return;
    }

    function getCheckboxes() {
        return document.querySelectorAll('.tso-backup-chk');
    }

    function formatSelectedCount(n) {
        if (n === 1 && backupCfg.selectedOne) {
            return backupCfg.selectedOne.replace('%d', String(n));
        }
        if (backupCfg.selectedMany) {
            return backupCfg.selectedMany.replace('%d', String(n));
        }
        return String(n) + (n === 1 ? ' selected' : ' selected');
    }

    function updateBulkBar() {
        var all = getCheckboxes();
        var checked = document.querySelectorAll('.tso-backup-chk:checked');
        var n = checked.length;

        countSpan.textContent = formatSelectedCount(n);
        bulkBtn.disabled = n === 0;
        selectAll.checked = all.length > 0 && n === all.length;
        selectAll.indeterminate = n > 0 && n < all.length;
    }

    selectAll.addEventListener('change', function () {
        getCheckboxes().forEach(function (chk) {
            chk.checked = selectAll.checked;
        });
        updateBulkBar();
    });

    getCheckboxes().forEach(function (chk) {
        chk.addEventListener('change', updateBulkBar);
    });

    form.addEventListener('submit', function (e) {
        var n = document.querySelectorAll('.tso-backup-chk:checked').length;
        if (n === 0) {
            e.preventDefault();
            return;
        }

        var msg = backupCfg.confirmBulk || 'Delete %d backup(s)?';
        msg = msg.replace('%d', String(n));
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });

    updateBulkBar();
})();
