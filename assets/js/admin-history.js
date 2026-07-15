(function () {
    'use strict';
    var admin = window.tsootcAdminConfig || {};
    var hist = window.tsootcHistoryConfig || {};
    var tsootcCommonJs = admin.common || {};
    var clearConfirm = hist.clearConfirm || '';
    var clearedMsg = hist.clearedMsg || '';
    var histBtnLabel = hist.histBtnLabel || '';

    window.tsootcHistoryClear = function () {
        if (!confirm(clearConfirm)) {
            return;
        }
        var btns = document.querySelectorAll('.tso-hist-clear-btn');
        btns.forEach(function (b) {
            b.disabled = true;
            b.textContent = '\u23F3...';
        });
        if (typeof window.tsootcPost !== 'function') {
            return;
        }
        window.tsootcPost('tsootc_clear_history', {}, function (data) {
            if (data && data.success) {
                alert(clearedMsg);
                location.reload();
            } else {
                btns.forEach(function (b) {
                    b.disabled = false;
                    b.textContent = histBtnLabel;
                });
                var err = data && data.data && data.data.msg ? data.data.msg : tsootcCommonJs.unknownLong || 'Error';
                alert((tsootcCommonJs.parseErrorPrefix || '') + err);
            }
        });
    };

    window.tsootcHistoryFilter = function () {
        var searchVal = (document.getElementById('tso-hist-search-live') || {}).value || '';
        var typeVal = (document.getElementById('tso-hist-type') || {}).value || '';
        var actionVal = (document.getElementById('tso-hist-action') || {}).value || '';
        var dateFrom = (document.getElementById('tso-hist-date-from') || {}).value || '';
        var dateTo = (document.getElementById('tso-hist-date-to') || {}).value || '';
        var tsFrom = dateFrom ? new Date(dateFrom + 'T00:00:00').getTime() / 1000 : 0;
        var tsTo = dateTo ? new Date(dateTo + 'T23:59:59').getTime() / 1000 : Infinity;
        var rows = document.querySelectorAll('#tso-hist-table tbody tr');
        searchVal = searchVal.toLowerCase();
        rows.forEach(function (row) {
            var type = row.getAttribute('data-type') || '';
            var action = row.getAttribute('data-action') || '';
            var name = row.getAttribute('data-name') || '';
            var ts = parseInt(row.getAttribute('data-ts') || '0', 10);
            var show = true;
            if (typeVal && type !== typeVal) {
                show = false;
            }
            if (actionVal && action !== actionVal) {
                show = false;
            }
            if (searchVal && name.indexOf(searchVal) === -1) {
                show = false;
            }
            if (tsFrom > 0 && ts < tsFrom) {
                show = false;
            }
            if (tsTo < Infinity && ts > tsTo) {
                show = false;
            }
            row.style.display = show ? '' : 'none';
        });
    };
})();
