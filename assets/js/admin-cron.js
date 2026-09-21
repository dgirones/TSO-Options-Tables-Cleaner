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
            if (ok && sched === 'nocallback' && tr.getAttribute('data-callback') !== '0') {
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

    function bindRowActions(scope) {
        scope.querySelectorAll('.tso-cron-unschedule').forEach(function (btn) {
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

        scope.querySelectorAll('.tso-cron-clear-hook').forEach(function (btn) {
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

        scope.querySelectorAll('.tso-cron-run').forEach(function (btn) {
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

        scope.querySelectorAll('.tso-cron-postpone').forEach(function (btn) {
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

        scope.querySelectorAll('.tso-cron-pause').forEach(function (btn) {
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
    }
    bindRowActions(document);

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

    // Auto-refresh without reloading the page: wait until the next scheduled event is due, then swap the
    // stats and table rows in place (no flash, filters kept) and wait again for the following event.
    (function () {
        var table = document.getElementById('tso-cron-events-table');
        if (!table) {
            return;
        }
        var serverNow = parseInt(table.getAttribute('data-now') || '0', 10);
        var loadedAt = Date.now();
        var timer = null;
        var overdueRetries = 0;
        var BUFFER_MS = 4000;        // let WP-Cron finish the event before re-reading the queue
        var MIN_DELAY_MS = 15000;
        var MAX_DELAY_MS = 6 * 60 * 60 * 1000;

        function nowSec() {
            return serverNow + Math.floor((Date.now() - loadedAt) / 1000);
        }

        function nextDelay() {
            var rows = document.querySelectorAll('#tso-cron-events-table tbody tr.tso-cron-event-row');
            var now = nowSec();
            var nextTs = 0;
            var hasOverdue = false;
            rows.forEach(function (tr) {
                var ts = parseInt(tr.getAttribute('data-ts') || '0', 10);
                if (!ts) {
                    return;
                }
                if (ts <= now) {
                    hasOverdue = true;
                } else if (!nextTs || ts < nextTs) {
                    nextTs = ts;
                }
            });
            if (hasOverdue && overdueRetries < 3) {
                // Overdue events are about to be spawned by WP-Cron: check again shortly, a few times only.
                overdueRetries++;
                return MIN_DELAY_MS;
            }
            if (!nextTs) {
                return 0;
            }
            return Math.min(MAX_DELAY_MS, Math.max(MIN_DELAY_MS, (nextTs - now) * 1000 + BUFFER_MS));
        }

        function schedule() {
            window.clearTimeout(timer);
            var delay = nextDelay();
            if (delay > 0) {
                timer = window.setTimeout(refresh, delay);
            }
        }

        function refresh() {
            if (document.hidden) {
                // Nothing to look at: wait until the tab is visible again.
                document.addEventListener('visibilitychange', function onVisible() {
                    if (!document.hidden) {
                        document.removeEventListener('visibilitychange', onVisible);
                        refresh();
                    }
                });
                return;
            }
            fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) {
                    return r.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newTable = doc.getElementById('tso-cron-events-table');
                    var curBody = table.querySelector('tbody');
                    var newBody = newTable ? newTable.querySelector('tbody') : null;
                    if (!newTable || !curBody || !newBody) {
                        return;
                    }
                    var curStats = document.querySelector('.tso-cron-wrap .tso-stats-grid');
                    var newStats = doc.querySelector('.tso-cron-wrap .tso-stats-grid');
                    if (curStats && newStats) {
                        curStats.innerHTML = newStats.innerHTML;
                    }
                    var curHook = document.getElementById('tso-cron-filter-hook');
                    var newHook = doc.getElementById('tso-cron-filter-hook');
                    if (curHook && newHook) {
                        var keep = curHook.value;
                        curHook.innerHTML = newHook.innerHTML;
                        curHook.value = keep;
                    }
                    curBody.innerHTML = newBody.innerHTML;
                    table.setAttribute('data-now', newTable.getAttribute('data-now') || '0');
                    serverNow = parseInt(table.getAttribute('data-now') || '0', 10);
                    loadedAt = Date.now();
                    bindRowActions(curBody);
                    applyCronLiveFilter();
                    schedule();
                })
                .catch(function () {
                    schedule();
                });
        }

        schedule();
    })();

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
