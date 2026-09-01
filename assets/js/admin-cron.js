(function () {
    'use strict';
    var cfg = window.tsootcCronConfig || {};
    var admin = window.tsootcAdminConfig || {};

    function rowData(tr) {
        return {
            hook: tr.getAttribute('data-hook'),
            timestamp: tr.getAttribute('data-ts'),
            args: tr.getAttribute('data-args'),
            schedule: tr.getAttribute('data-schedule') || '',
            interval: tr.getAttribute('data-interval') || '0',
            core: tr.getAttribute('data-core') === '1'
        };
    }

    function post(action, payload, onOk) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', admin.nonce || '');
        Object.keys(payload).forEach(function (k) {
            fd.append(k, payload[k]);
        });
        fetch(admin.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (res) {
                if (res && res.success) {
                    if (onOk) {
                        onOk(res);
                    } else {
                        location.reload();
                    }
                } else {
                    alert(cfg.error + (res && res.data && res.data.msg ? res.data.msg : ''));
                }
            })
            .catch(function (err) {
                alert(cfg.error + err);
            });
    }

    function applyCronLiveFilter() {
        var hookEl = document.getElementById('tso-cron-filter-hook');
        var schedEl = document.getElementById('tso-cron-filter-sched');
        var qEl = document.getElementById('tso-cron-filter-q');
        var emptyRow = document.getElementById('tso-cron-filter-empty');
        var hook = hookEl ? String(hookEl.value || '') : '';
        var sched = schedEl ? String(schedEl.value || '') : '';
        var q = qEl ? String(qEl.value || '').toLowerCase().trim() : '';
        var rows = document.querySelectorAll('#tso-cron-events-table tbody tr.tso-cron-event-row');
        var visible = 0;

        rows.forEach(function (tr) {
            var rowHook = String(tr.getAttribute('data-hook') || '');
            var recurring = tr.getAttribute('data-recurring') === '1';
            var overdue = tr.getAttribute('data-overdue') === '1';
            var ok = true;

            if (hook && rowHook !== hook) {
                ok = false;
            }
            if (ok && sched === 'recurring' && !recurring) {
                ok = false;
            }
            if (ok && sched === 'single' && recurring) {
                ok = false;
            }
            if (ok && sched === 'overdue' && !overdue) {
                ok = false;
            }
            if (ok && q && rowHook.toLowerCase().indexOf(q) === -1) {
                ok = false;
            }

            tr.style.display = ok ? '' : 'none';
            if (ok) {
                visible++;
            }
        });

        if (emptyRow) {
            if (visible === 0) {
                emptyRow.classList.remove('tso-u-hidden');
                emptyRow.style.display = '';
            } else {
                emptyRow.classList.add('tso-u-hidden');
                emptyRow.style.display = 'none';
            }
        }
    }

    var cronFilterForm = document.getElementById('tso-cron-filter-form');
    if (cronFilterForm) {
        cronFilterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            applyCronLiveFilter();
        });
    }
    var cronHook = document.getElementById('tso-cron-filter-hook');
    var cronSched = document.getElementById('tso-cron-filter-sched');
    var cronQ = document.getElementById('tso-cron-filter-q');
    if (cronHook) {
        cronHook.addEventListener('change', applyCronLiveFilter);
    }
    if (cronSched) {
        cronSched.addEventListener('change', applyCronLiveFilter);
    }
    if (cronQ) {
        cronQ.addEventListener('input', applyCronLiveFilter);
        cronQ.addEventListener('search', applyCronLiveFilter);
    }
    applyCronLiveFilter();

    document.querySelectorAll('.tso-cron-unschedule').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var d = rowData(tr);
            var msg = d.core ? cfg.confirmDeleteCore : cfg.confirmDelete;
            if (!confirm(msg)) {
                return;
            }
            post('tsootc_cron_unschedule', { hook: d.hook, timestamp: d.timestamp, args: d.args }, function () {
                tr.remove();
            });
        });
    });

    document.querySelectorAll('.tso-cron-clear-hook').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var d = rowData(tr);
            if (!confirm(cfg.confirmClear)) {
                return;
            }
            post('tsootc_cron_clear_hook', { hook: d.hook }, function () {
                location.reload();
            });
        });
    });

    document.querySelectorAll('.tso-cron-run').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = rowData(btn.closest('tr'));
            if (!confirm(cfg.confirmRun)) {
                return;
            }
            post('tsootc_cron_run_now', {
                hook: d.hook,
                args: d.args,
                timestamp: d.timestamp,
                schedule: d.schedule
            }, function (res) {
                alert(res.data && res.data.msg ? res.data.msg : cfg.ok);
                location.reload();
            });
        });
    });

    document.querySelectorAll('.tso-cron-postpone').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = rowData(btn.closest('tr'));
            post('tsootc_cron_postpone', {
                hook: d.hook,
                timestamp: d.timestamp,
                args: d.args,
                schedule: d.schedule,
                interval: d.interval,
                minutes: '60'
            }, function () {
                location.reload();
            });
        });
    });

    document.querySelectorAll('.tso-cron-pause').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var d = rowData(tr);
            if (!confirm(cfg.confirmPause)) {
                return;
            }
            post('tsootc_cron_pause', {
                hook: d.hook,
                timestamp: d.timestamp,
                args: d.args,
                schedule: d.schedule,
                interval: d.interval
            }, function () {
                tr.remove();
                location.reload();
            });
        });
    });

    document.querySelectorAll('.tso-cron-resume').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-pause-id');
            if (!confirm(cfg.confirmResume)) {
                return;
            }
            post('tsootc_cron_resume', { pause_id: id }, function () {
                location.reload();
            });
        });
    });

    document.querySelectorAll('.tso-cron-delete-paused').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-pause-id');
            if (!confirm(cfg.confirmDelete)) {
                return;
            }
            post('tsootc_cron_delete_paused', { pause_id: id }, function () {
                tr.remove();
            });
        });
    });
})();
