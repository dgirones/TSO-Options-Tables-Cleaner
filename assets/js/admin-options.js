(function () {
    'use strict';
    var cfg = window.tsootcOptionsConfig || {};
    var admin = window.tsootcAdminConfig || {};
    var tsootcAjaxUrl = admin.ajaxUrl || '';
    var tsootcNonce = admin.nonce || '';
    var tsootcLang = admin.lang || {};
    var tsootcCommonJs = admin.common || {};

    function commonJs() {
        return (typeof tsootcCommonJs !== 'undefined' && tsootcCommonJs) ? tsootcCommonJs : {};
    }

    function tsootcSessionGet(key, legacyKey) {
        var v = sessionStorage.getItem(key);
        if (v === null && legacyKey) {
            v = sessionStorage.getItem(legacyKey);
        }
        return v;
    }

    function tsootcSessionRemove(key, legacyKey) {
        try {
            sessionStorage.removeItem(key);
            if (legacyKey) {
                sessionStorage.removeItem(legacyKey);
            }
        } catch (e) {
            // ignore
        }
    }

    // Restaurar scroll i estat grups
    (function () {
        var sc = parseInt(tsootcSessionGet('tsootc_scroll', 'tso_scroll') || '0', 10);
        var col = {};
        try {
            col = JSON.parse(tsootcSessionGet('tsootc_collapsed', 'tso_collapsed') || '{}');
        } catch (e) {
            col = {};
        }
        if (sc || Object.keys(col).length) {
            tsootcSessionRemove('tsootc_scroll', 'tso_scroll');
            tsootcSessionRemove('tsootc_collapsed', 'tso_collapsed');
            window.addEventListener('load', function () {
                Object.keys(col).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) {
                        return;
                    }
                    if (col[id] === 'closed') {
                        el.classList.remove('open');
                    } else {
                        el.classList.add('open');
                        var h = el.previousElementSibling;
                        if (h) {
                            h.classList.add('open');
                        }
                    }
                });
                setTimeout(function () {
                    window.scrollTo({ top: sc, behavior: 'instant' });
                }, 30);
            });
        }
    })();

    window.tsootcSaveState = function () {
        sessionStorage.setItem('tsootc_scroll', window.scrollY || document.documentElement.scrollTop);
        var st = {};
        document.querySelectorAll('.tso-plugin-group-body').forEach(function (el) {
            st[el.id] = el.classList.contains('open') ? 'open' : 'closed';
        });
        sessionStorage.setItem('tsootc_collapsed', JSON.stringify(st));
    };

    window.tsootcToggleGroup = function (headEl, evt) {
        if (evt && evt.target && evt.target !== headEl) {
            var t = evt.target;
            while (t && t !== headEl) {
                if (t.tagName === 'BUTTON' || t.tagName === 'A' || t.tagName === 'INPUT') {
                    return;
                }
                t = t.parentElement;
            }
        }
        var body = headEl.nextElementSibling;
        if (!body) {
            return;
        }
        body.classList.toggle('open');
        headEl.classList.toggle('open');
    };

    var tsootcDeleteQueue = [];
    var tsootcDeleteQueueTimer = null;
    var tsootcDeleteInFlight = false;

    function tsootcOptsFiltersActive() {
        var s = document.getElementById('tso-opts-search');
        var a = document.getElementById('tso-opts-autoload');
        var f = document.getElementById('tso-opts-safety');
        return !!((s && s.value) || (a && a.value) || (f && f.value));
    }

    function tsootcUpdateGroupMeta(grp) {
        if (!grp) {
            return;
        }
        var rows = grp.getElementsByClassName('tso-opts-row');
        if (!rows.length) {
            grp.style.display = 'none';
            return;
        }
        var meta = grp.querySelector('.grp-meta');
        if (!meta) {
            return;
        }
        var txt = meta.textContent || '';
        var parts = txt.split('\u00b7');
        var suffix = parts.length > 1 ? ' \u00b7' + parts.slice(1).join('\u00b7') : '';
        var word = parts[0].replace(/^\d+\s*/, '').trim();
        if (!word) {
            word = cfg.entriesWord || 'entries';
        }
        meta.textContent = rows.length + ' ' + word + suffix;
    }

    function tsootcPostOptionDeletes(names, cb) {
        var fd = new FormData();
        fd.append('action', names.length > 1 ? 'tsootc_delete_options_bulk' : 'tsootc_delete_option');
        fd.append('_ajax_nonce', tsootcNonce);
        if (names.length === 1) {
            fd.append('option_name', names[0]);
        } else {
            names.forEach(function (n) {
                fd.append('option_names[]', n);
            });
        }
        fetch(tsootcAjaxUrl + '?action=' + encodeURIComponent(names.length > 1 ? 'tsootc_delete_options_bulk' : 'tsootc_delete_option') + '&nocache=' + Date.now(), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Cache-Control': 'no-cache, no-store' }
        })
            .then(function (r) {
                return r.text();
            })
            .then(function (text) {
                try {
                    cb(JSON.parse(text));
                } catch (e) {
                    cb({
                        success: false,
                        data: {
                            msg: (commonJs().parseErrorPrefix || 'Error: ') + text.substring(0, 200)
                        }
                    });
                }
            })
            .catch(function (e) {
                cb({
                    success: false,
                    data: { msg: (commonJs().networkError || 'Network error: ') + e }
                });
            });
    }

    function tsootcShowOptsNotice(msg) {
        var wrap = document.getElementById('tso-wrap');
        if (!wrap || !msg) {
            return;
        }
        var n = document.createElement('div');
        n.className = 'tso-notice-success';
        n.innerHTML = '<span class="tso-notice-icon">\u2705</span> ' + msg;
        wrap.insertBefore(n, wrap.firstChild);
        setTimeout(function () {
            if (n.parentNode) {
                n.parentNode.removeChild(n);
            }
        }, 4500);
    }

    function tsoRemoveDeletedOptionRows(names, scopeEl) {
        if (!names || !names.length) {
            return;
        }
        var set = {};
        for (var i = 0; i < names.length; i++) {
            set[names[i]] = true;
        }
        var touched = [];
        var root = scopeEl || document.getElementById('tso-opts-wrap');
        if (!root) {
            return;
        }
        var rows = root.getElementsByClassName ? root.getElementsByClassName('tso-opts-row') : root.querySelectorAll('.tso-opts-row');
        for (var r = rows.length - 1; r >= 0; r--) {
            var row = rows[r];
            if (!set[row.dataset.name]) {
                continue;
            }
            var grp = row.closest ? row.closest('.tso-plugin-group') : null;
            if (grp && touched.indexOf(grp) === -1) {
                touched.push(grp);
            }
            row.parentNode.removeChild(row);
        }
        for (var g = 0; g < touched.length; g++) {
            tsootcUpdateGroupMeta(touched[g]);
        }
        if (tsootcOptsFiltersActive() && typeof window.tsootcFilterOpts === 'function') {
            window.tsootcFilterOpts();
        }
    }

    function tsootcFlushDeleteQueue() {
        if (tsootcDeleteInFlight || !tsootcDeleteQueue.length) {
            return;
        }
        var batch = tsootcDeleteQueue.slice();
        tsootcDeleteQueue = [];
        tsootcDeleteInFlight = true;
        tsootcPostOptionDeletes(batch, function (data) {
            tsootcDeleteInFlight = false;
            if (data && data.success) {
                var msg = (data.data && data.data.msg) ? data.data.msg : '';
                if (!msg) {
                    msg = batch.length > 1
                        ? (commonJs().optionsDeletedMany || '%d').replace('%d', String(batch.length))
                        : commonJs().optionsDeletedOne;
                }
                tsootcShowOptsNotice(msg);
                if (tsootcDeleteQueue.length) {
                    tsootcFlushDeleteQueue();
                }
            } else {
                alert(
                    (commonJs().parseErrorPrefix || 'Error: ') +
                    (data && data.data ? data.data.msg : (commonJs().unknownShort || '?')) +
                    '\n' +
                    (cfg.reloadSync || '')
                );
            }
        });
    }

    function tsootcQueueOptionDeletes(names) {
        if (!names || !names.length) {
            return;
        }
        for (var i = 0; i < names.length; i++) {
            if (tsootcDeleteQueue.indexOf(names[i]) === -1) {
                tsootcDeleteQueue.push(names[i]);
            }
        }
        clearTimeout(tsootcDeleteQueueTimer);
        if (!tsootcDeleteInFlight) {
            tsootcDeleteQueueTimer = setTimeout(tsootcFlushDeleteQueue, 120);
        }
    }

    window.tsootcDeleteOnePost = function (name, confirmMsg, rowId) {
        if (!confirm(confirmMsg || ((commonJs().deleteOptionPrefix || '\u2757 ') + name + '?'))) {
            return;
        }
        var row = rowId ? document.getElementById(rowId) : null;
        if (!row) {
            var all = document.getElementsByClassName('tso-opts-row');
            for (var i = 0; i < all.length; i++) {
                if (all[i].dataset.name === name) {
                    row = all[i];
                    break;
                }
            }
        }
        var scope = row ? (row.closest ? row.closest('form') : null) : null;
        tsoRemoveDeletedOptionRows([name], scope || undefined);
        tsootcQueueOptionDeletes([name]);
    };

    window.tsootcAssignSelected = function (formId) {
        var f = document.getElementById(formId);
        if (!f) {
            return;
        }
        var checked = f.querySelectorAll('input[type=checkbox][name="option_names[]"]:checked');
        if (checked.length === 0) {
            alert(cfg.selectAtLeast || '');
            return;
        }
        var names = [];
        checked.forEach(function (c) {
            names.push(c.value);
        });
        if (typeof window.tsootcOpenBulkAssign === 'function') {
            window.tsootcOpenBulkAssign(names);
        }
    };

    window.tsootcClearOptsBulkSelection = function () {
        document.querySelectorAll('input[type=checkbox][name="option_names[]"]').forEach(function (c) {
            c.checked = false;
        });
    };

    window.tsootcDeleteSelected = function (formId) {
        var f = document.getElementById(formId);
        if (!f) {
            return;
        }
        var checked = f.querySelectorAll('input[type=checkbox][name="option_names[]"]:checked');
        if (checked.length === 0) {
            alert(cfg.selectAtLeast || '');
            return;
        }
        if (!confirm(tsootcLang.confirmDelete + checked.length + tsootcLang.tablesSelectedPl + '?')) {
            return;
        }
        var names = [];
        checked.forEach(function (c) {
            names.push(c.value);
        });
        var delBtn = f.closest('.tso-plugin-group') && f.closest('.tso-plugin-group').querySelector('.tso-plugin-group-head button.button');
        var prevLbl = delBtn ? delBtn.textContent : '';
        if (delBtn) {
            delBtn.disabled = true;
            delBtn.textContent = commonJs().optionsDeleting || '...';
        }
        tsoRemoveDeletedOptionRows(names, f);
        tsootcPostOptionDeletes(names, function (data) {
            if (delBtn) {
                delBtn.disabled = false;
                delBtn.textContent = prevLbl;
            }
            if (data && data.success) {
                var msg = (data.data && data.data.msg) ? data.data.msg : '';
                if (!msg) {
                    msg = (commonJs().optionsDeletedMany || '%d').replace('%d', String(names.length));
                }
                tsootcShowOptsNotice(msg);
            } else {
                alert(
                    (commonJs().parseErrorPrefix || 'Error: ') +
                    (data && data.data ? data.data.msg : (commonJs().unknownShort || '?')) +
                    '\n' +
                    (cfg.reloadSync || '')
                );
            }
        });
    };

    window.tsootcSelectAll = function (chk, formId) {
        var f = document.getElementById(formId);
        if (!f) {
            return;
        }
        f.querySelectorAll('input[type=checkbox][name="option_names[]"]').forEach(function (c) {
            c.checked = chk.checked;
        });
    };

    function tsootcUpdateAutoloadRow(rowId, autoloadValue, isOn) {
        var cell = document.getElementById('auto-' + rowId);
        if (cell) {
            var badgeClass = isOn ? 'yes' : 'no';
            var badgeText = isOn ? 'yes' : 'no';
            cell.innerHTML = '<span class="tso-badge tso-badge-' + badgeClass + '">' + badgeText + '</span>';
        }
        var row = document.getElementById(rowId);
        if (row) {
            row.dataset.autoload = autoloadValue;
        }
        var btn = document.getElementById('autobtn-' + rowId);
        if (!btn) {
            return;
        }
        if (isOn) {
            btn.textContent = '🔇 auto';
            btn.title = btn.getAttribute('data-title-off') || '';
            btn.classList.remove('on');
            btn.setAttribute('data-tso-act', 'option-autoload-off');
        } else {
            btn.textContent = '🔊 auto';
            btn.title = btn.getAttribute('data-title-on') || '';
            btn.classList.add('on');
            btn.setAttribute('data-tso-act', 'option-autoload-on');
        }
    }

    window.tsootcAutoloadOff = function (name, rowId) {
        if (!confirm((cfg.disableAutoloadPrefix || '') + name + '?')) {
            return;
        }
        if (typeof window.tsootcPost !== 'function') {
            return;
        }
        window.tsootcPost('tsootc_disable_autoload', { option_name: name }, function (data) {
            if (data && data.success) {
                tsootcUpdateAutoloadRow(rowId, 'no', false);
            } else {
                alert(
                    (commonJs().parseErrorPrefix || 'Error: ') +
                    (data && data.data ? data.data.msg : (commonJs().unknownShort || '?'))
                );
            }
        });
    };

    window.tsootcAutoloadOn = function (name, rowId) {
        if (!confirm((cfg.enableAutoloadPrefix || '') + name + '?')) {
            return;
        }
        if (typeof window.tsootcPost !== 'function') {
            return;
        }
        window.tsootcPost('tsootc_enable_autoload', { option_name: name }, function (data) {
            if (data && data.success) {
                tsootcUpdateAutoloadRow(rowId, 'yes', true);
            } else {
                alert(
                    (commonJs().parseErrorPrefix || 'Error: ') +
                    (data && data.data ? data.data.msg : (commonJs().unknownShort || '?'))
                );
            }
        });
    };

    function tsootcSyncOptsSearchClear() {
        var searchEl = document.getElementById('tso-opts-search');
        var clearBtn = document.getElementById('tso-opts-search-clear');
        if (!searchEl || !clearBtn) {
            return;
        }
        var hasText = String(searchEl.value || '').trim().length > 0;
        if (hasText) {
            clearBtn.removeAttribute('hidden');
        } else {
            clearBtn.setAttribute('hidden', 'hidden');
        }
    }

    window.tsootcFilterOpts = function () {
        var searchEl = document.getElementById('tso-opts-search');
        var search = String((searchEl && searchEl.value) ? searchEl.value : '').toLowerCase().trim();
        var auto = (document.getElementById('tso-opts-autoload') || { value: '' }).value;
        var safety = (document.getElementById('tso-opts-safety') || { value: '' }).value;
        var offVals = ['no', 'off', '0', ''];

        tsootcSyncOptsSearchClear();

        document.querySelectorAll('.tso-plugin-group').forEach(function (grp) {
            var groupHay = (grp.getAttribute('data-search') || '').toLowerCase();
            var groupMatch = search && groupHay.indexOf(search) !== -1;
            var anyVisible = false;

            grp.querySelectorAll('.tso-opts-row').forEach(function (row) {
                var name = (row.dataset.name || '').toLowerCase();
                var ral = row.dataset.autoload || '';
                var rsal = row.dataset.safety || '';
                var isOn = offVals.indexOf(ral) === -1;
                var ok = true;
                if (search && !groupMatch && name.indexOf(search) === -1) {
                    ok = false;
                }
                if (auto === 'on' && !isOn) {
                    ok = false;
                }
                if (auto === 'off' && isOn) {
                    ok = false;
                }
                if (safety && rsal !== safety) {
                    ok = false;
                }
                row.style.display = ok ? '' : 'none';
                if (ok) {
                    anyVisible = true;
                }
            });

            grp.style.display = anyVisible ? '' : 'none';

            if (search && groupMatch && anyVisible) {
                var head = grp.querySelector('.tso-plugin-group-head');
                var body = grp.querySelector('.tso-plugin-group-body');
                if (head) {
                    head.classList.add('open');
                }
                if (body) {
                    body.classList.add('open');
                }
            }
        });
    };

    function tsootcBindOptsSearchClear() {
        var clearBtn = document.getElementById('tso-opts-search-clear');
        var searchEl = document.getElementById('tso-opts-search');
        if (!clearBtn || !searchEl || clearBtn.getAttribute('data-tsootc-bound') === '1') {
            return;
        }
        clearBtn.setAttribute('data-tsootc-bound', '1');
        clearBtn.addEventListener('click', function () {
            searchEl.value = '';
            tsootcSyncOptsSearchClear();
            if (typeof window.tsootcFilterOpts === 'function') {
                window.tsootcFilterOpts();
            }
            searchEl.focus();
        });
        tsootcSyncOptsSearchClear();
    }

    function tsootcFindActButton(target, root) {
        var node = target;
        while (node && node !== root) {
            if (node.nodeType === 1 && node.getAttribute && node.getAttribute('data-tso-act')) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    function tsootcResolveActButton(ev, root) {
        var target = ev.target;
        if (!target) {
            return null;
        }
        if (target.nodeType === 3 && target.parentNode) {
            target = target.parentNode;
        }
        if (target.closest) {
            return target.closest('[data-tso-act]');
        }
        return tsootcFindActButton(target, root);
    }

    function tsootcBindOptsWrapActions() {
        var wrap = document.getElementById('tso-opts-wrap');
        if (!wrap || wrap.getAttribute('data-tsootc-acts-bound') === '1') {
            return;
        }
        wrap.setAttribute('data-tsootc-acts-bound', '1');
        wrap.addEventListener('click', function (ev) {
            var btn = tsootcResolveActButton(ev, wrap);
            if (!btn || !wrap.contains(btn)) {
                return;
            }
            var act = btn.getAttribute('data-tso-act');
            if (act === 'option-view') {
                ev.preventDefault();
                var n = btn.getAttribute('data-option-name');
                if (n && typeof window.tsootcShowValue === 'function') {
                    window.tsootcShowValue(n);
                }
                return;
            }
            if (act === 'option-assign') {
                ev.preventDefault();
                var n2 = btn.getAttribute('data-option-name');
                if (n2 && typeof window.tsootcOpenAssign === 'function') {
                    window.tsootcOpenAssign(n2);
                }
                return;
            }
            if (act === 'option-confirm') {
                ev.preventDefault();
                var nconf = btn.getAttribute('data-option-name');
                var hint = btn.getAttribute('data-hint-label') || '';
                if (nconf && typeof window.tsootcConfirmDetection === 'function') {
                    window.tsootcConfirmDetection(nconf, hint, btn);
                }
                return;
            }
            if (act === 'option-autoload-off') {
                ev.preventDefault();
                var n3 = btn.getAttribute('data-option-name');
                var rid = btn.getAttribute('data-row-id');
                if (n3 && rid && typeof window.tsootcAutoloadOff === 'function') {
                    window.tsootcAutoloadOff(n3, rid);
                }
                return;
            }
            if (act === 'option-autoload-on') {
                ev.preventDefault();
                var n3on = btn.getAttribute('data-option-name');
                var ridon = btn.getAttribute('data-row-id');
                if (n3on && ridon && typeof window.tsootcAutoloadOn === 'function') {
                    window.tsootcAutoloadOn(n3on, ridon);
                }
                return;
            }
            if (act === 'option-delete') {
                ev.preventDefault();
                var n4 = btn.getAttribute('data-option-name');
                var cmsg = btn.getAttribute('data-confirm-msg') || '';
                var rid4 = btn.getAttribute('data-row-id') || '';
                if (n4 && typeof window.tsootcDeleteOnePost === 'function') {
                    window.tsootcDeleteOnePost(n4, cmsg, rid4);
                }
            }
        });
    }

    function tsootcInitOptsTabUi() {
        tsootcBindOptsWrapActions();
        tsootcBindOptsSearchClear();
        if (tsootcOptsFiltersActive() && typeof window.tsootcFilterOpts === 'function') {
            window.tsootcFilterOpts();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tsootcInitOptsTabUi);
    } else {
        tsootcInitOptsTabUi();
    }
})();
