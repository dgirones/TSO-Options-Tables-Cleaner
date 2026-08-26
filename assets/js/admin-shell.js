(function () {
    'use strict';
    var cfg = window.tsootcAdminConfig || {};
    var tsootcAjaxUrl = cfg.ajaxUrl || '';
    var tsootcNonce = cfg.nonce || '';
    var tsootcLang = cfg.lang || {};
    var tsootcOptimizeJs = cfg.optimize || {};
    var tsootcCommonJs = cfg.common || {};
    var tsootcRenameUi = cfg.rename || {};
    var tsootcAutoCleanStrings = cfg.autoClean || {};
    var tsootcTableInspectorI18n = cfg.tableInspector || {};
    var tsootcModalCopyI18n = cfg.modalCopy || {};

function tsootcParseAjaxJson(text) {
        var raw = String(text || '').replace(/^\uFEFF/, '').trim();
        if (raw === '') {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e1) {
            var start = raw.indexOf('{');
            var end = raw.lastIndexOf('}');
            if (start !== -1 && end > start) {
                try {
                    return JSON.parse(raw.slice(start, end + 1));
                } catch (e2) {
                    return null;
                }
            }
        }
        return null;
    }

    function tsootcNormalizeAjaxResponse(parsed) {
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        if (typeof parsed.success === 'string') {
            parsed.success = (parsed.success === 'true' || parsed.success === '1');
        } else if (typeof parsed.success === 'number') {
            parsed.success = parsed.success === 1;
        }
        if (parsed.data && typeof parsed.data.msg === 'string') {
            var inner = tsootcParseAjaxJson(parsed.data.msg);
            if (inner && (inner.success === true || inner.success === 'true' || inner.success === 1)) {
                return tsootcNormalizeAjaxResponse(inner);
            }
        }
        if (typeof parsed.success === 'boolean') {
            return parsed;
        }
        if (parsed.data && typeof parsed.data === 'object') {
            parsed.success = false;
            return parsed;
        }
        return null;
    }

    function tsootcAjaxSucceeded(res) {
        return !!(res && (res.success === true || res.success === 'true' || res.success === 1));
    }

    function tsootcCoerceAjaxBody(body) {
        if (body && typeof body === 'object') {
            return tsootcNormalizeAjaxResponse(body);
        }
        return tsootcNormalizeAjaxResponse(tsootcParseAjaxJson(body));
    }

    function tsootcPost(action, data, cb) {
        var fd = new FormData();
        fd.append("action", action);
        fd.append("_ajax_nonce", tsootcNonce);
        Object.keys(data).forEach(function(k){
            if (Object.prototype.toString.call(data[k]) === "[object Array]") {
                data[k].forEach(function(v){ fd.append(k + "[]", v); });
            } else {
                fd.append(k, data[k]);
            }
        });
        fetch(tsootcAjaxUrl + "?nocache=" + Date.now(), {
            method:"POST", body:fd, credentials:"same-origin",
            headers:{"Cache-Control":"no-cache, no-store"}
        })
        .then(function(r) {
            var ct = (r.headers.get('content-type') || '').toLowerCase();
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function(json) {
                    return json;
                }).catch(function() {
                    return r.text();
                });
            }
            return r.text();
        })
        .then(function(body) {
            var parsed = tsootcCoerceAjaxBody(body);
            if (!parsed && typeof body === 'string') {
                parsed = tsootcNormalizeAjaxResponse(tsootcParseAjaxJson(body));
            }
            if (parsed) {
                cb(parsed);
                return;
            }
            var prefix = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.parseErrorPrefix : 'Error: ');
            var snippet = typeof body === 'string' ? body.substring(0, 200) : String(body);
            cb({success:false, data:{msg: prefix + snippet}});
        })
        .catch(function(e){ cb({success:false, data:{msg:(typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.networkError : 'Network error: ')+e}}); });
    }
    window.tsootcPost = tsootcPost;

    window.tsootcFragTpl = function(tpl, vals) {
        if (!tpl) return '';
        var n = (vals && vals.n != null) ? vals.n : '';
        return tpl.replace(/\{before\}/g, String(vals.before)).replace(/\{after\}/g, String(vals.after)).replace(/\{freed\}/g, String(vals.freed)).replace(/\{n\}/g, String(n));
    };

    window.tsootcRunOptimize = function() {
        var oj = typeof tsootcOptimizeJs !== 'undefined' ? tsootcOptimizeJs : {};
        if (!confirm(oj.confirm || '')) return;
        var btn = document.getElementById("tso-btn-optimize");
        if (btn) { btn.disabled = true; btn.textContent = oj.btnBusy || ''; }
        var resDiv = document.getElementById("tso-optimize-results");
        if (resDiv) resDiv.style.display = "none";
        var sumEl = document.getElementById("tso-optimize-summary");
        if (sumEl) { sumEl.style.display = "none"; sumEl.textContent = ""; }
        tsootcPost("tsootc_optimize_tables", {}, function(data) {
            if (btn) { btn.disabled = false; btn.textContent = oj.btnLabel || ''; }
            if (!tsootcAjaxSucceeded(data)) {
                alert((oj.errorPrefix || '') + (data && data.data ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownShort : '')));
                return;
            }
            var rows = data.data.results || [];
            var beforeKb = parseInt(data.data.fragmented_kb_before, 10);
            if (isNaN(beforeKb)) beforeKb = 0;
            var afterKb = parseInt(data.data.fragmented_kb_after, 10);
            if (isNaN(afterKb)) afterKb = 0;
            var freedKb = Math.max(0, beforeKb - afterKb);

            var st = document.getElementById("tso-optimize-frag-status");
            if (st) {
                if (afterKb > 0) {
                    st.innerHTML = '<span style="color:#c07000;font-weight:700">⚠️ ' + afterKb.toLocaleString() + ' KB <span>' + (oj.fragWord || '') + '</span></span>';
                } else {
                    st.innerHTML = '<span style="color:#46b450;font-weight:700">✅ ' + (oj.fragNoTitle || '') + '</span>';
                }
            }
            var subEl = document.getElementById("tso-optimize-frag-sub");
            if (subEl) {
                var pv = (data.data.frag_sub_preview != null && data.data.frag_sub_preview !== '') ? String(data.data.frag_sub_preview) : '';
                subEl.textContent = pv || (oj.allOptimizedSub || '');
            }

            if (sumEl) {
                var summaryText = '';
                if (beforeKb === 0 && afterKb === 0) {
                    summaryText = (oj.noFragMaintTpl || '').replace(/\{n\}/g, String(rows.length));
                } else {
                    summaryText = window.tsootcFragTpl(oj.fragSummaryTpl, {
                        before: beforeKb.toLocaleString(),
                        after: afterKb.toLocaleString(),
                        freed: freedKb.toLocaleString(),
                        n: rows.length
                    });
                    if (freedKb === 0 && beforeKb > 0) {
                        summaryText += ' ' + (oj.schemaStaleNote || '');
                    }
                }
                sumEl.textContent = summaryText;
                sumEl.style.display = "block";
            }

            var hdr  = document.getElementById("tso-optimize-header");
            if (hdr) {
                hdr.style.display = "flex";
                hdr.textContent = (oj.headerSep || '') + rows.length + ' ' + (oj.tablesProcessed || '');
            }
            var cont = document.getElementById("tso-optimize-rows");
            if (!cont) return;
            cont.innerHTML = "";
            rows.forEach(function(r) {
                var d = document.createElement("div");
                d.className = "tso-opt-row";
                d.innerHTML = '<span class="tso-opt-table">' + r.table + '</span>' +
                    '<span class="tso-opt-msg">' + r.msg + '</span>' +
                    '<span class="tso-opt-kb">' + r.kb.toLocaleString() + ' KB</span>';
                cont.appendChild(d);
            });
            var foot = document.createElement("div");
            foot.className = "tso-opt-row";
            foot.style.cssText = "background:#f0f6fc;font-weight:600";
            foot.innerHTML = '<span class="tso-opt-table"></span>' +
                '<span class="tso-opt-msg" style="color:#007cba">' + (oj.completedNoErrors || '') + '</span>' +
                '<span class="tso-opt-kb" style="color:#007cba;font-size:11px">' + (freedKb > 0 ? ('~' + freedKb.toLocaleString() + ' KB DATA_FREE') : '—') + '</span>';
            cont.appendChild(foot);
            if (resDiv) resDiv.style.display = "block";
        });
    };

    // Estat intern del modal d'edició
    var tsootcModalCurrentName = '';
    var tsootcModalRawValue    = '';
    var tsootcModalIsTable     = false;

    function tsootcFormatBrowserDateTime(ts) {
        var n = parseInt(ts, 10);
        if (!n) return '';
        var d = new Date(n * 1000);
        try {
            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            }).format(d);
        } catch (e) {
            var pad = function(v) { return String(v).padStart(2, '0'); };
            return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    }

    function tsootcRefreshAutoScheduleUi(nextTs, enabled) {
        var nextWrap = document.getElementById('tso-auto-next-wrap');
        var nextValue = document.getElementById('tso-auto-next-value');
        var offWrap = document.getElementById('tso-auto-off-wrap');
        if (nextWrap) {
            if (nextTs) {
                nextWrap.style.display = '';
                nextWrap.setAttribute('data-ts', String(nextTs));
                if (nextValue) nextValue.textContent = tsootcFormatBrowserDateTime(nextTs);
            } else {
                nextWrap.style.display = 'none';
                nextWrap.setAttribute('data-ts', '0');
                if (nextValue) nextValue.textContent = '';
            }
        }
        if (offWrap) {
            offWrap.style.display = (!nextTs && !enabled) ? '' : 'none';
        }
    }

    function tsootcShowCleanupNotice(message, isWarning) {
        var flash = document.getElementById('tso-cleanup-flash');
        if (!flash) {
            return;
        }
        var icon = isWarning ? '⚠️' : '✅';
        var cls = isWarning ? 'tso-notice-warning' : 'tso-notice-success';
        flash.innerHTML = '<div class="' + cls + '"><span class="tso-notice-icon">' + icon + '</span> ' + escHtml(String(message || '')) + '</div>';
        flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function tsootcRefreshCleanupDashboardStats(statsDisplay) {
        if (!statsDisplay || typeof statsDisplay !== 'object') {
            return;
        }
        Object.keys(statsDisplay).forEach(function(key) {
            var card = document.querySelector('.tso-stat-card[data-stat-key="' + key + '"] .tso-stat-value');
            if (card) {
                card.textContent = statsDisplay[key];
            }
        });
    }

    function tsootcFormatCleanupCountText(count) {
        var cleanupI18n = (cfg.cleanup || {});
        var zero = parseInt(count, 10) === 0;
        return zero
            ? ('0' + (cleanupI18n.alreadyClean || ''))
            : (String(count) + (cleanupI18n.entries || ' entries'));
    }

    function tsootcUpdateCleanupCardCount(form, count) {
        if (!form) {
            return;
        }
        var card = form.closest('.tso-action-card');
        if (!card) {
            return;
        }
        var countEl = card.querySelector('.tso-action-count');
        var zero = parseInt(count, 10) === 0;
        if (countEl) {
            countEl.className = 'tso-action-count' + (zero ? ' zero' : '');
            countEl.textContent = tsootcFormatCleanupCountText(count);
        }
        var submitBtn = form.querySelector('.tso-cleanup-submit');
        if (!submitBtn) {
            return;
        }
        if (!submitBtn.getAttribute('data-label')) {
            submitBtn.setAttribute('data-label', submitBtn.textContent);
        }
        if (zero) {
            submitBtn.disabled = true;
            submitBtn.classList.add('button-disabled');
            submitBtn.classList.remove('button-primary');
            submitBtn.textContent = ((cfg.cleanup && cfg.cleanup.nothingClean) || '✅ Nothing to clean');
        } else {
            submitBtn.disabled = false;
            submitBtn.classList.remove('button-disabled');
            submitBtn.classList.add('button-primary');
            submitBtn.textContent = submitBtn.getAttribute('data-label') || submitBtn.textContent;
        }
    }

    function tsootcUpdateCleanupCardCountByKey(actionKey, count) {
        if (!actionKey) {
            return;
        }
        var card = document.querySelector('.tso-action-card[data-cleanup-action="' + actionKey + '"]');
        if (!card) {
            return;
        }
        var form = card.querySelector('form.tso-cleanup-form');
        if (form) {
            tsootcUpdateCleanupCardCount(form, count);
            return;
        }
        var countEl = card.querySelector('.tso-action-count');
        var zero = parseInt(count, 10) === 0;
        if (countEl) {
            countEl.className = 'tso-action-count' + (zero ? ' zero' : '');
            countEl.textContent = tsootcFormatCleanupCountText(count);
        }
    }

    function tsootcUpdateAutoActionCountText(actionKey, count) {
        var el = document.querySelector('.tso-auto-action-count[data-cleanup-action="' + actionKey + '"]');
        if (!el || 'optimize_fragmented_tables' === actionKey) {
            return;
        }
        el.textContent = tsootcFormatCleanupCountText(count);
    }

    function tsootcRefreshCleanupActionCounts(actionCounts) {
        if (!actionCounts || typeof actionCounts !== 'object') {
            return;
        }
        Object.keys(actionCounts).forEach(function(key) {
            tsootcUpdateCleanupCardCountByKey(key, actionCounts[key]);
            tsootcUpdateAutoActionCountText(key, actionCounts[key]);
        });
    }

    var tsootcRetentionPreviewTimer = null;

    function tsootcCollectRetentionDaysPayload() {
        var payload = {};
        document.querySelectorAll('.tso-auto-retention').forEach(function(el) {
            var k = el.getAttribute('data-retention-key');
            if (k) {
                payload['retention_days[' + k + ']'] = el.value;
            }
        });
        document.querySelectorAll('form.tso-cleanup-form input[name^="retention_days"]').forEach(function(input) {
            var keyMatch = (input.name || '').match(/retention_days\[([^\]]+)\]/);
            if (keyMatch && keyMatch[1]) {
                payload['retention_days[' + keyMatch[1] + ']'] = input.value;
            }
        });
        return payload;
    }

    function tsootcBuildRetentionPreviewPayload(actionKey) {
        var payload = tsootcCollectRetentionDaysPayload();
        if (actionKey) {
            payload.cleanup_action = actionKey;
        }
        return payload;
    }

    function tsootcScheduleCleanupCountPreview(payload) {
        if (!tsootcAjaxUrl || !tsootcNonce) {
            return;
        }
        clearTimeout(tsootcRetentionPreviewTimer);
        tsootcRetentionPreviewTimer = setTimeout(function() {
            tsootcPost('tsootc_get_cleanup_counts', payload || {}, function(res) {
                if (!tsootcAjaxSucceeded(res) || !res.data) {
                    return;
                }
                tsootcRefreshCleanupDashboardStats(res.data.stats_display);
                tsootcRefreshCleanupActionCounts(res.data.action_counts);
            });
        }, 350);
    }

    document.querySelectorAll('form.tso-cleanup-form').forEach(function(form) {
        var submitBtn = form.querySelector('.tso-cleanup-submit');
        if (submitBtn && !submitBtn.getAttribute('data-label')) {
            submitBtn.setAttribute('data-label', submitBtn.textContent);
        }
        form.addEventListener('submit', function(ev) {
            var btn = ev.submitter || submitBtn;
            var confirmMsg = btn ? btn.getAttribute('data-confirm') : '';
            if (confirmMsg && !confirm(confirmMsg)) {
                ev.preventDefault();
                return;
            }
            if (!tsootcAjaxUrl || !tsootcNonce) {
                return;
            }
            ev.preventDefault();
            var actionKey = form.getAttribute('data-cleanup-action') || '';
            var fd = new FormData(form);
            var payload = { cleanup_action: actionKey };
            fd.forEach(function(value, key) {
                if (key.indexOf('retention_days[') === 0) {
                    payload[key] = value;
                }
            });
            if (btn) {
                btn.disabled = true;
                btn.textContent = (cfg.cleanup && cfg.cleanup.busy) ? cfg.cleanup.busy : '⏳...';
            }
            tsootcPost('tsootc_run_cleanup', payload, function(res) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.getAttribute('data-label') || btn.textContent;
                }
                if (!tsootcAjaxSucceeded(res)) {
                    var err = (res && res.data && res.data.msg) ? res.data.msg : ((cfg.common && cfg.common.unknownLong) || 'Error');
                    tsootcShowCleanupNotice(err, true);
                    return;
                }
                tsootcShowCleanupNotice((res.data && res.data.msg) ? res.data.msg : '', false);
                if (typeof res.data.count !== 'undefined') {
                    tsootcUpdateCleanupCardCount(form, res.data.count);
                }
                tsootcRefreshCleanupDashboardStats(res.data.stats_display);
                tsootcRefreshCleanupActionCounts(res.data.action_counts);
            });
        });
        var retentionInput = form.querySelector('input[name^="retention_days"]');
        if (retentionInput) {
            retentionInput.addEventListener('input', function() {
                var actionKey = form.getAttribute('data-cleanup-action') || '';
                tsootcScheduleCleanupCountPreview(tsootcBuildRetentionPreviewPayload(actionKey));
            });
        }
    });

    document.querySelectorAll('.tso-auto-retention').forEach(function(input) {
        input.addEventListener('input', function() {
            var key = input.getAttribute('data-retention-key') || '';
            tsootcScheduleCleanupCountPreview(tsootcBuildRetentionPreviewPayload(key));
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        var cleanupFlash = document.getElementById('tso-cleanup-flash');
        if (cleanupFlash && cleanupFlash.textContent.trim() !== '') {
            cleanupFlash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        var lastWrap = document.getElementById('tso-auto-last-wrap');
        var lastValue = document.getElementById('tso-auto-last-value');
        if (lastWrap && lastValue) {
            var lastTs = parseInt(lastWrap.getAttribute('data-ts') || '0', 10);
            if (lastTs) lastValue.textContent = tsootcFormatBrowserDateTime(lastTs);
        }
        var nextWrap = document.getElementById('tso-auto-next-wrap');
        var nextTs = nextWrap ? parseInt(nextWrap.getAttribute('data-ts') || '0', 10) : 0;
        var enabled = !!(document.getElementById('tso-auto-enabled') && document.getElementById('tso-auto-enabled').checked);
        tsootcRefreshAutoScheduleUi(nextTs, enabled);
    });

    window.tsootcSaveAutoclean = function() {
        var enabled  = document.getElementById('tso-auto-enabled') && document.getElementById('tso-auto-enabled').checked ? '1' : '';
        var interval = document.getElementById('tso-auto-interval') ? document.getElementById('tso-auto-interval').value : 'weekly';
        var email    = document.getElementById('tso-auto-email') && document.getElementById('tso-auto-email').checked ? '1' : '';
        var browserTimezone = '';
        try {
            browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            browserTimezone = '';
        }
        var actions  = [];
        document.querySelectorAll('.tso-auto-action:checked').forEach(function(el){ actions.push(el.value); });
        var msg = document.getElementById('tso-auto-msg');
        if (msg) { msg.style.color = '#666'; msg.textContent = '⏳...'; }
        var data = {enabled: enabled, interval: interval, email: email, browser_timezone: browserTimezone};
        actions.forEach(function(a, i){ data['actions['+i+']'] = a; });
        document.querySelectorAll('.tso-auto-retention').forEach(function(el){
            var key = el.getAttribute('data-retention-key');
            if (!key) return;
            data['retention_days[' + key + ']'] = el.value;
        });
        tsootcPost('tsootc_save_auto_clean', data, function(res) {
            if (res && res.success) {
                var nextTs = res.data && res.data.next_ts ? parseInt(res.data.next_ts, 10) : 0;
                var effectiveEnabled = !!(res.data && typeof res.data.enabled !== 'undefined' ? res.data.enabled : enabled);
                var autoEnabledCheckbox = document.getElementById('tso-auto-enabled');
                if (autoEnabledCheckbox) autoEnabledCheckbox.checked = effectiveEnabled;
                if (msg) {
                    if (res.data && res.data.status === 'warning') {
                        msg.style.color = '#c07000';
                        msg.textContent = '⚠️ ' + (res.data.msg || '');
                    } else {
                        msg.style.color = '#2e7d32';
                        msg.textContent = '✅ ' + (nextTs ? (tsootcAutoCleanStrings.savedOk + tsootcFormatBrowserDateTime(nextTs)) : tsootcAutoCleanStrings.savedOff);
                    }
                }
                tsootcRefreshAutoScheduleUi(nextTs, effectiveEnabled);
                tsootcScheduleCleanupCountPreview(tsootcCollectRetentionDaysPayload());
            } else {
                var errMsg = (res && res.data && res.data.msg) ? res.data.msg : 'Error';
                if (msg) { msg.style.color = '#c00'; msg.textContent = '❌ ' + errMsg; }
            }
        });
    };

    // ---- Renombrar grup ----
    var tsootcRenameGroupKey = '';
    var tsootcRenameGroupId  = '';

    window.tsootcOpenRenameGroup = function(groupKey, groupId, currentName) {
        tsootcRenameGroupKey = groupKey;
        tsootcRenameGroupId  = groupId;
        var lbl = document.getElementById('tso-rename-orig-label');
        var inp = document.getElementById('tso-rename-input');
        var msg = document.getElementById('tso-rename-msg');
        if (lbl) {
            lbl.textContent = (typeof tsootcRenameUi !== 'undefined' && tsootcRenameUi.originalPrefix ? tsootcRenameUi.originalPrefix + ' ' : '') + groupKey;
        }
        if (inp) { inp.value = currentName; setTimeout(function(){ inp.focus(); inp.select(); }, 80); }
        if (msg) msg.textContent = '';
        var ov = document.getElementById('tso-rename-overlay');
        if (ov) ov.classList.add('active');
    };

    window.tsootcSaveGroupAlias = function() {
        var inp = document.getElementById('tso-rename-input');
        var msg = document.getElementById('tso-rename-msg');
        if (!inp) return;
        var alias = inp.value.trim();
        tsootcPost('tsootc_save_group_alias', {group_key: tsootcRenameGroupKey, alias: alias}, function(data) {
            if (data && data.success) {
                // Actualitzar el títol visible del grup
                var titleEl = document.getElementById('grp-title-' + tsootcRenameGroupId);
                if (titleEl) titleEl.textContent = alias || tsootcRenameGroupKey;
                // Buscar el botó rename: es el button.tso-rename-btn just després del span del títol
                var btn = titleEl ? titleEl.nextElementSibling : null;
                if (btn && btn.classList.contains('tso-rename-btn')) {
                    if (alias) btn.classList.add('has-alias');
                    else btn.classList.remove('has-alias');
                }
                if (msg) { msg.style.color = '#2e7d32'; msg.textContent = '✅'; }
                setTimeout(function(){
                    var ov = document.getElementById('tso-rename-overlay');
                    if (ov) ov.classList.remove('active');
                }, 600);
            } else {
                if (msg) { msg.style.color = '#c00'; msg.textContent = '❌ Error'; }
            }
        });
    };

    window.tsootcResetGroupAlias = function() {
        var inp = document.getElementById('tso-rename-input');
        if (inp) inp.value = '';
        tsootcSaveGroupAlias();
    };

    document.addEventListener('keydown', function(e) {
        var ov = document.getElementById('tso-rename-overlay');
        if (e.key === 'Enter' && ov && ov.classList.contains('active')) {
            e.preventDefault(); tsootcSaveGroupAlias();
        }
    });

    // ---- Visor arbre JSON/serialitzat ----
    function tsootcRenderTree(data, container) {
        container.innerHTML = '';
        container.appendChild(tsootcTreeNode(data, null, 0));
    }

    function tsootcTreeNode(val, key, depth) {
        var wrap = document.createElement('div');
        wrap.className = 'tso-tree-node';
        var type = typeof val;
        if (val === null) type = 'null';
        else if (Array.isArray(val)) type = 'array';
        else if (type === 'object') type = 'object';

        var keySpan = '';
        if (key !== null) {
            keySpan = '<span class="tso-tree-key">' + escHtml(String(key)) + '</span><span style="color:#999">: </span>';
        }

        if (type === 'object' || type === 'array') {
            var keys = Object.keys(val);
            var count = keys.length;
            var badge = '<span class="tso-tree-type-badge">' + (type === 'array' ? 'array' : 'object') + ' · ' + count + '</span>';
            var toggle = document.createElement('span');
            toggle.className = 'tso-tree-toggle';
            toggle.innerHTML = '▼';
            var label = document.createElement('span');
            label.innerHTML = keySpan + (type === 'array' ? '[' : '{') + badge;
            var children = document.createElement('div');
            children.className = 'tso-tree-children';
            keys.forEach(function(k) {
                children.appendChild(tsootcTreeNode(val[k], k, depth + 1));
            });
            var close = document.createElement('div');
            close.style.color = '#555';
            close.textContent = type === 'array' ? ']' : '}';
            toggle.addEventListener('click', function() {
                var collapsed = children.classList.toggle('collapsed');
                toggle.innerHTML = collapsed ? '▶' : '▼';
            });
            // Auto-col·lapsar a partir de profunditat 2
            if (depth >= 2) { children.classList.add('collapsed'); toggle.innerHTML = '▶'; }
            wrap.appendChild(toggle);
            wrap.appendChild(label);
            wrap.appendChild(children);
            wrap.appendChild(close);
        } else if (type === 'string') {
            wrap.innerHTML = keySpan + '<span class="tso-tree-str">"' + escHtml(val) + '"</span>';
        } else if (type === 'number') {
            wrap.innerHTML = keySpan + '<span class="tso-tree-num">' + val + '</span>';
        } else if (type === 'boolean') {
            wrap.innerHTML = keySpan + '<span class="tso-tree-bool">' + (val ? 'true' : 'false') + '</span>';
        } else if (type === 'null') {
            wrap.innerHTML = keySpan + '<span class="tso-tree-null">null</span>';
        }
        return wrap;
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var tsootcModalHasTree = false;
    var tsootcModalParsedTree = null;

    function tsootcResetTableInspector() {
        var tableView = document.getElementById('tso-modal-table');
        if (tableView) {
            tableView.style.display = 'none';
            tableView.innerHTML = '';
        }
    }

    function tsootcModalText(value) {
        if (value === null || value === undefined || value === '') return tsootcTableInspectorI18n.notAvailable;
        return String(value);
    }

    function tsootcCreateTableSection(title) {
        var section = document.createElement('section');
        section.className = 'tso-modal-section';
        var heading = document.createElement('h3');
        heading.textContent = title;
        section.appendChild(heading);
        return section;
    }

    function tsootcCreateDataTable(headers, rows, opts) {
        var mode = opts && opts.mode ? opts.mode : 'default';
        var wrap = document.createElement('div');
        wrap.className = 'tso-modal-table-wrap' + (mode === 'sample' ? ' is-sample' : '');
        var table = document.createElement('table');
        table.className = 'tso-modal-table-grid' + (mode === 'sample' ? ' is-sample' : '');
        var thead = document.createElement('thead');
        var headRow = document.createElement('tr');

        headers.forEach(function(header) {
            var th = document.createElement('th');
            th.textContent = header;
            headRow.appendChild(th);
        });

        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        rows.forEach(function(row) {
            var tr = document.createElement('tr');
            row.forEach(function(cell) {
                var td = document.createElement('td');
                td.textContent = cell === null || cell === undefined || cell === '' ? tsootcTableInspectorI18n.notAvailable : String(cell);
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }

    function tsootcRenderTableMessage(message) {
        var tableView = document.getElementById('tso-modal-table');
        var pre = document.getElementById('tso-modal-value');
        var tree = document.getElementById('tso-modal-tree');
        if (pre) pre.style.display = 'none';
        if (tree) tree.style.display = 'none';
        if (!tableView) return;
        tableView.innerHTML = '';
        tableView.style.display = 'block';
        var box = document.createElement('div');
        box.className = 'tso-modal-empty';
        box.textContent = message;
        tableView.appendChild(box);
    }

    function tsootcRenderTableInspector(details, fallbackText) {
        var tableView = document.getElementById('tso-modal-table');
        var pre = document.getElementById('tso-modal-value');
        var tree = document.getElementById('tso-modal-tree');
        if (!tableView) return;

        tableView.innerHTML = '';
        tableView.style.display = 'block';
        if (pre) pre.style.display = 'none';
        if (tree) tree.style.display = 'none';

        if (!details || !details.overview) {
            tsootcRenderTableMessage(fallbackText || tsootcTableInspectorI18n.notAvailable);
            return;
        }

        var overviewSection = tsootcCreateTableSection(tsootcTableInspectorI18n.overview);
        var overviewGrid = document.createElement('div');
        overviewGrid.className = 'tso-modal-meta-grid';
        [
            [tsootcTableInspectorI18n.engine, details.overview.engine],
            [tsootcTableInspectorI18n.rowFormat, details.overview.row_format],
            [tsootcTableInspectorI18n.collation, details.overview.collation],
            [tsootcTableInspectorI18n.rowsApprox, details.overview.rows_approx],
            [tsootcTableInspectorI18n.dataSize, details.overview.data_kb + ' KB'],
            [tsootcTableInspectorI18n.indexSize, details.overview.index_kb + ' KB'],
            [tsootcTableInspectorI18n.freeSize, details.overview.free_kb + ' KB'],
            [tsootcTableInspectorI18n.totalSize, details.overview.total_kb + ' KB'],
            [tsootcTableInspectorI18n.autoIncrement, details.overview.auto_increment],
            [tsootcTableInspectorI18n.created, details.overview.created, details.overview.created_hint],
            [tsootcTableInspectorI18n.updated, details.overview.updated, details.overview.updated_hint],
            [tsootcTableInspectorI18n.columnsCount, details.overview.columns_count],
            [tsootcTableInspectorI18n.indexesCount, details.overview.indexes_count]
        ].forEach(function(item) {
            var card = document.createElement('div');
            card.className = 'tso-modal-meta-card';
            var label = document.createElement('span');
            label.className = 'tso-modal-meta-label';
            label.textContent = item[0];
            var value = document.createElement('span');
            value.className = 'tso-modal-meta-value';
            var displayValue = tsootcModalText(item[1]);
            if (displayValue !== '') {
                value.textContent = displayValue;
            } else {
                value.textContent = tsootcTableInspectorI18n.notAvailable;
                value.classList.add('is-unknown');
            }
            card.appendChild(label);
            card.appendChild(value);
            if (item[2]) {
                var hint = document.createElement('span');
                hint.className = 'tso-modal-meta-hint';
                hint.textContent = String(item[2]);
                card.appendChild(hint);
            }
            overviewGrid.appendChild(card);
        });
        overviewSection.appendChild(overviewGrid);
        tableView.appendChild(overviewSection);

        var columnsSection = tsootcCreateTableSection(tsootcTableInspectorI18n.columns);
        var columnRows = (details.columns || []).map(function(column) {
            return [
                column.name,
                column.type,
                column.nullable ? tsootcTableInspectorI18n.yes : tsootcTableInspectorI18n.no,
                column.key,
                column.default,
                column.extra
            ];
        });
        if (columnRows.length) {
            columnsSection.appendChild(tsootcCreateDataTable(
                [tsootcTableInspectorI18n.colName, tsootcTableInspectorI18n.colType, tsootcTableInspectorI18n.colNullable, tsootcTableInspectorI18n.colKey, tsootcTableInspectorI18n.colDefault, tsootcTableInspectorI18n.colExtra],
                columnRows
            ));
        } else {
            var noColumns = document.createElement('div');
            noColumns.className = 'tso-modal-empty';
            noColumns.textContent = tsootcTableInspectorI18n.notAvailable;
            columnsSection.appendChild(noColumns);
        }
        tableView.appendChild(columnsSection);

        var indexesSection = tsootcCreateTableSection(tsootcTableInspectorI18n.indexes);
        var indexRows = (details.indexes || []).map(function(indexRow) {
            return [
                indexRow.name,
                indexRow.type,
                indexRow.unique ? tsootcTableInspectorI18n.yes : tsootcTableInspectorI18n.no,
                (indexRow.columns || []).join(', ')
            ];
        });
        if (indexRows.length) {
            indexesSection.appendChild(tsootcCreateDataTable(
                [tsootcTableInspectorI18n.idxName, tsootcTableInspectorI18n.idxType, tsootcTableInspectorI18n.idxUnique, tsootcTableInspectorI18n.idxColumns],
                indexRows
            ));
        } else {
            var noIndexes = document.createElement('div');
            noIndexes.className = 'tso-modal-empty';
            noIndexes.textContent = tsootcTableInspectorI18n.notAvailable;
            indexesSection.appendChild(noIndexes);
        }
        tableView.appendChild(indexesSection);

        var sampleTpl = tsootcTableInspectorI18n.sampleLimitTpl || '%1$s (LIMIT %2$d)';
        var sampleTitle = sampleTpl
            .replace('%1$s', tsootcTableInspectorI18n.sampleRows)
            .replace('%2$d', String(details.sample_limit || 0));
        var sampleSection = tsootcCreateTableSection(sampleTitle);
        if (details.sample_rows && details.sample_rows.length && details.columns && details.columns.length) {
            var sampleHeaders = details.columns.map(function(column) { return column.name; });
            var sampleRows = details.sample_rows.map(function(row) {
                return sampleHeaders.map(function(columnName) {
                    return Object.prototype.hasOwnProperty.call(row, columnName) ? row[columnName] : '';
                });
            });
            sampleSection.appendChild(tsootcCreateDataTable(sampleHeaders, sampleRows, {mode: 'sample'}));
        } else {
            var noRows = document.createElement('div');
            noRows.className = 'tso-modal-empty';
            noRows.textContent = tsootcTableInspectorI18n.noRows;
            sampleSection.appendChild(noRows);
        }
        tableView.appendChild(sampleSection);

        var createSection = tsootcCreateTableSection(tsootcTableInspectorI18n.createTable);
        var createPre = document.createElement('pre');
        createPre.className = 'tso-modal-code';
        createPre.textContent = details.create_sql || fallbackText || tsootcTableInspectorI18n.notAvailable;
        createSection.appendChild(createPre);
        tableView.appendChild(createSection);
    }

    function tsootcCopyTextToClipboard(text, onDone) {
        if (!text) {
            alert(tsootcModalCopyI18n.empty);
            return;
        }
        var finish = function(ok) {
            if (typeof onDone === 'function') {
                onDone(ok);
            } else if (ok) {
                alert(tsootcModalCopyI18n.copied);
            } else {
                alert(tsootcModalCopyI18n.failed);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() { finish(true); }).catch(function() {
                tsootcCopyTextToClipboardFallback(text, finish);
            });
            return;
        }
        tsootcCopyTextToClipboardFallback(text, finish);
    }

    function tsootcCopyTextToClipboardFallback(text, finish) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(ta);
        finish(ok);
    }

    function tsootcModalGetCopyText() {
        var pre = document.getElementById('tso-modal-value');
        var tree = document.getElementById('tso-modal-tree');
        var tableView = document.getElementById('tso-modal-table');
        var editor = document.getElementById('tso-modal-editor');

        if (editor && editor.style.display !== 'none' && editor.style.display !== '') {
            return editor.value || '';
        }
        if (tsootcModalIsTable && tableView && tableView.style.display !== 'none') {
            return (tableView.innerText || tableView.textContent || '').trim();
        }
        if (tree && tree.style.display !== 'none' && tsootcModalParsedTree !== null) {
            try {
                return JSON.stringify(tsootcModalParsedTree, null, 2);
            } catch (e) {
                return (tree.innerText || tree.textContent || '').trim();
            }
        }
        if (pre && pre.style.display !== 'none') {
            return (pre.textContent || '').trim();
        }
        var raw = tsootcModalRawValue || '';
        if (!raw) {
            return '';
        }
        try {
            return JSON.stringify(JSON.parse(raw), null, 2);
        } catch (e) {
            return raw;
        }
    }

    window.tsootcModalCopyAll = function() {
        var btn = document.getElementById('tso-tab-copy');
        var text = tsootcModalGetCopyText();
        tsootcCopyTextToClipboard(text, function(ok) {
            if (!ok) {
                return;
            }
            if (btn) {
                if (!btn.getAttribute('data-copy-label')) {
                    btn.setAttribute('data-copy-label', btn.textContent);
                }
                btn.textContent = '✓';
                setTimeout(function() {
                    btn.textContent = btn.getAttribute('data-copy-label');
                }, 1600);
            } else {
                alert(tsootcModalCopyI18n.copied);
            }
        });
    };

    window.tsootcSwitchTab = function(tab) {
        var pre    = document.getElementById('tso-modal-value');
        var tree   = document.getElementById('tso-modal-tree');
        var tabT   = document.getElementById('tso-tab-tree');
        var tabR   = document.getElementById('tso-tab-raw');
        if (tab === 'tree') {
            if (pre)  pre.style.display  = 'none';
            if (tree) tree.style.display = 'block';
            if (tabT) tabT.classList.add('active');
            if (tabR) tabR.classList.remove('active');
        } else {
            if (pre)  pre.style.display  = 'block';
            if (tree) tree.style.display = 'none';
            if (tabT) tabT.classList.remove('active');
            if (tabR) tabR.classList.add('active');
        }
    };

    window.tsootcShowValue = function(name) {
        tsootcModalCurrentName = name;
        tsootcModalIsTable     = false;
        tsootcModalCancelEdit();
        tsootcModalHasTree = false;
        tsootcModalParsedTree = null;
        var editBtn = document.getElementById('tso-modal-edit-btn');
        if (editBtn) editBtn.style.display = '';
        var tabs  = document.getElementById('tso-modal-view-tabs');
        var pre   = document.getElementById('tso-modal-value');
        var tree  = document.getElementById('tso-modal-tree');
        var badge = document.getElementById('tso-modal-type-badge');
        var ov    = document.getElementById('tso-modal-overlay');
        var nm    = document.getElementById('tso-modal-name');
        tsootcResetTableInspector();
        if (tabs)  tabs.style.display  = 'none';
        if (tree)  { tree.style.display = 'none'; tree.innerHTML = ''; }
        if (pre)   pre.style.display   = 'block';
        if (badge) badge.style.display = 'none';
        if (nm)    nm.textContent = name;
        if (pre)   pre.textContent = tsootcTableInspectorI18n.loading || '…';
        if (ov)    ov.classList.add('active');

        tsootcPost("tsootc_get_option_value", {option_name:name}, function(data) {
            if (!data || !data.success) {
                if (pre) {
                    pre.textContent = (data && data.data && data.data.msg)
                        ? data.data.msg
                        : (tsootcTableInspectorI18n.notAvailable || 'Error');
                }
                return;
            }
            var d = data.data;
            var el = document.getElementById("tso-modal-name");
            if (el) el.textContent = name;
            tsootcModalRawValue = d.value || '';

            // Vista RAW
            var rawDisplay = tsootcModalRawValue || (tsootcTableInspectorI18n.emptyRaw || '(empty)');
            try { rawDisplay = JSON.stringify(JSON.parse(tsootcModalRawValue), null, 2); } catch(e){}
            if (pre) pre.textContent = rawDisplay;

            // Si és PHP serialitzat -> mostrar arbre llegible + tabs
            if (d.is_serialized && d.parsed) {
                try {
                    var parsed = JSON.parse(d.parsed);
                    tsootcModalParsedTree = parsed;
                    if (tree) { tsootcRenderTree(parsed, tree); tsootcModalHasTree = true; }
                    if (tabs)  tabs.style.display  = 'flex';
                    if (badge) { badge.textContent = tsootcTableInspectorI18n.serializedBadge || '🔢 PHP serialized'; badge.style.display = 'inline-block'; }
                    tsootcSwitchTab('tree');
                } catch(e) {
                    if (pre) pre.style.display = 'block';
                }
            }

            var ov = document.getElementById("tso-modal-overlay");
            if (ov) ov.classList.add("active");
        });
    };

    window.tsootcModalToggleEdit = function() {
        var pre    = document.getElementById('tso-modal-value');
        var editor = document.getElementById('tso-modal-editor');
        var bar    = document.getElementById('tso-modal-edit-bar');
        var btn    = document.getElementById('tso-modal-edit-btn');
        if (!editor) return;
        if (editor.style.display === 'none' || editor.style.display === '') {
            // Entrar en mode edició
            editor.value = tsootcModalRawValue;
            editor.style.display = 'block';
            if (pre) pre.style.display = 'none';
            if (bar) bar.classList.add('visible');
            if (btn) { btn.textContent = tsootcTableInspectorI18n.previewLabel || '👁️ Preview'; btn.classList.add('editing'); }
            editor.focus();
        } else {
            // Tornar a vista prèvia (sense desar)
            tsootcModalCancelEdit();
        }
    };

    window.tsootcModalCancelEdit = function() {
        var pre    = document.getElementById('tso-modal-value');
        var tree   = document.getElementById('tso-modal-tree');
        var editor = document.getElementById('tso-modal-editor');
        var bar    = document.getElementById('tso-modal-edit-bar');
        var btn    = document.getElementById('tso-modal-edit-btn');
        var msg    = document.getElementById('tso-modal-save-msg');
        var tableView = document.getElementById('tso-modal-table');
        if (editor) { editor.style.display = 'none'; editor.value = ''; }
        if (bar)    bar.classList.remove('visible');
        if (msg)    msg.textContent = '';
        if (btn)    { btn.textContent = tsootcTableInspectorI18n.editLabel || '✏️ Edit'; btn.classList.remove('editing'); }
        if (tsootcModalIsTable) {
            if (pre)  pre.style.display = 'none';
            if (tree) tree.style.display = 'none';
            if (tableView) tableView.style.display = 'block';
            return;
        }
        // Restaurar la vista adequada (arbre si en tenim, raw si no)
        if (tsootcModalHasTree) {
            tsootcSwitchTab('tree');
        } else {
            if (pre)  pre.style.display  = 'block';
            if (tree) tree.style.display = 'none';
            if (tableView) tableView.style.display = 'none';
        }
    };

    window.tsootcModalSave = function() {
        var editor  = document.getElementById('tso-modal-editor');
        var saveBtn = document.getElementById('tso-modal-save-btn');
        var msg     = document.getElementById('tso-modal-save-msg');
        if (!editor || !tsootcModalCurrentName) return;
        var newValue = editor.value;
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = tsootcTableInspectorI18n.saving || '⏳ Saving...'; }
        if (msg) msg.textContent = '';
        tsootcPost('tsootc_save_option_value', {option_name: tsootcModalCurrentName, option_value: newValue}, function(data) {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = tsootcTableInspectorI18n.saveChange || '💾 Save change'; }
            if (data && data.success) {
                tsootcModalRawValue = newValue;
                // Actualitzar vista prèvia
                var display = newValue || (tsootcTableInspectorI18n.emptyRaw || '(empty)');
                try { display = JSON.stringify(JSON.parse(newValue), null, 2); } catch(e){ display = newValue; }
                var pre = document.getElementById('tso-modal-value');
                if (pre) pre.textContent = display;
                if (msg) { msg.style.color = '#2e7d32'; msg.textContent = '✅ ' + (data.data.msg || tsootcTableInspectorI18n.saved || 'Saved!'); }
            } else {
                var errMsg = (data && data.data && data.data.msg) ? data.data.msg : (tsootcTableInspectorI18n.saveError || 'Error saving.');
                if (msg) { msg.style.color = '#c00'; msg.textContent = '❌ ' + errMsg; }
            }
        });
    };

    // ---- Assignar opció a plugin (mapa personalitzat) ----
    function tsootcIsSyntheticAssignGroup(key) {
        key = String(key || '');
        if (key === '__core__' || key === '__unknown__' || key === '__widgets__') {
            return true;
        }
        if (key.indexOf('\u2753 ') === 0) {
            return true;
        }
        // ASCII fallback when the emoji renders as "?" (no /u regex — older JS engines).
        if (key.charAt(0) === '?' && key.length > 3 && key.slice(-2) === '_*') {
            return true;
        }
        return false;
    }

    function tsootcGetAssignGroups() {
        var o = window.tsootcOtcOptionsConfig || window.tsootcOptionsConfig || {};
        var a = window.tsootcAdminConfig || {};
        return o.assignGroups || a.assignGroups || {};
    }

    window.tsootcRegisterAssignGroup = function (internalKey, displayLabel) {
        internalKey = String(internalKey || '');
        displayLabel = String(displayLabel || internalKey);
        if (!internalKey || tsootcIsSyntheticAssignGroup(internalKey)) {
            return;
        }
        [window.tsootcOtcOptionsConfig, window.tsootcOptionsConfig, window.tsootcAdminConfig].forEach(function (cfg) {
            if (!cfg) {
                return;
            }
            if (!cfg.assignGroups) {
                cfg.assignGroups = {};
            }
            cfg.assignGroups[internalKey] = displayLabel;
        });
    };

    function tsootcResetAssignModalButtons() {
        var btnExisting = document.getElementById("tso-assign-save-existing");
        var btnNew = document.getElementById("tso-assign-save-new");
        var cfg = window.tsootcOtcOptionsConfig || window.tsootcAdminConfig || {};
        if (btnExisting) {
            btnExisting.disabled = false;
            btnExisting.textContent = btnExisting.getAttribute("data-default-label")
                || cfg.assignBtnExisting
                || "Assign to group";
        }
        if (btnNew) {
            btnNew.disabled = false;
            btnNew.textContent = btnNew.getAttribute("data-default-label")
                || cfg.assignBtnNew
                || "Create and assign";
        }
    }

    window.tsootcResetAssignModalButtons = tsootcResetAssignModalButtons;

    window.tsootcConfirmDetection = function (optionName, hintLabel, btnEl) {
        var cfg = window.tsootcOtcOptionsConfig || window.tsootcOptionsConfig || {};
        var adminCfg = window.tsootcAdminConfig || {};
        var confirmMsg = cfg.confirmDetectionPrompt || adminCfg.confirmDetectionPrompt
            || 'Confirm automatic assignment for this key?';
        if (!confirm(confirmMsg + '\n\n' + optionName)) {
            return;
        }
        if (btnEl) {
            btnEl.disabled = true;
        }
        tsootcPost('tsootc_confirm_detection', {
            option_name: optionName,
            hint_label: hintLabel || ''
        }, function (data) {
            if (data && data.success) {
                if (typeof window.tsootcSaveState === 'function') {
                    window.tsootcSaveState();
                }
                location.reload();
                return;
            }
            if (btnEl) {
                btnEl.disabled = false;
            }
            var errMsg = (data && data.data && data.data.msg) ? data.data.msg : (cfg.confirmError || 'Error');
            alert(errMsg);
        });
    };

    window.tsootcConfirmTableDetection = function (tableName, hintLabel, btnEl) {
        var cfg = window.tsootcOtcOptionsConfig || window.tsootcOptionsConfig || {};
        var adminCfg = window.tsootcAdminConfig || {};
        var confirmMsg = cfg.confirmDetectionPrompt || adminCfg.confirmDetectionPrompt
            || 'Confirm automatic assignment for this table?';
        if (!confirm(confirmMsg + '\n\n' + tableName)) {
            return;
        }
        if (btnEl) {
            btnEl.disabled = true;
        }
        tsootcPost('tsootc_confirm_table_detection', {
            table_name: tableName,
            hint_label: hintLabel || ''
        }, function (data) {
            if (data && data.success) {
                location.reload();
                return;
            }
            if (btnEl) {
                btnEl.disabled = false;
            }
            var errMsg = (data && data.data && data.data.msg) ? data.data.msg : (cfg.confirmError || 'Error');
            alert(errMsg);
        });
    };

    window.tsootcOpenAssign = function(optionName) {
        var ov  = document.getElementById("tso-assign-overlay");
        var nm  = document.getElementById("tso-assign-option-name");
        var sel = document.getElementById("tso-assign-existing-select");
        var inp = document.getElementById("tso-assign-new-input");
        if (!ov) return;
        tsootcResetAssignModalButtons();
        delete ov.dataset.optionNames;
        delete ov.dataset.tableName;
        ov.dataset.optionName = optionName;
        if (nm)  nm.textContent = optionName;
        if (inp) inp.value = "";
        tsootcFillAssignGroupSelect(sel);
        ov.classList.add("active");
    };

    window.tsootcOpenTableAssign = function (tableName) {
        var ov  = document.getElementById("tso-assign-overlay");
        var nm  = document.getElementById("tso-assign-option-name");
        var sel = document.getElementById("tso-assign-existing-select");
        var inp = document.getElementById("tso-assign-new-input");
        if (!ov) return;
        tsootcResetAssignModalButtons();
        delete ov.dataset.optionNames;
        delete ov.dataset.optionName;
        ov.dataset.tableName = tableName;
        if (nm)  nm.textContent = tableName;
        if (inp) inp.value = "";
        tsootcFillAssignGroupSelect(sel);
        ov.classList.add("active");
    };

    function tsootcFillAssignGroupSelect(sel) {
        if (!sel) {
            return;
        }
        var optsCfg = window.tsootcOtcOptionsConfig || window.tsootcOptionsConfig || {};
        var adminCfg = window.tsootcAdminConfig || {};
        var placeholder = optsCfg.assignSelectPlaceholder || adminCfg.assignSelectPlaceholder || '-- Select a group --';
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        Object.keys(tsootcGetAssignGroups()).forEach(function(internalKey) {
            if (tsootcIsSyntheticAssignGroup(internalKey)) {
                return;
            }
            var groups = tsootcGetAssignGroups();
            var opt = document.createElement("option");
            opt.value       = internalKey;
            opt.textContent = groups[internalKey];
            sel.appendChild(opt);
        });
    }

    window.tsootcOpenBulkAssign = function(optionNames) {
        var ov  = document.getElementById("tso-assign-overlay");
        var nm  = document.getElementById("tso-assign-option-name");
        var sel = document.getElementById("tso-assign-existing-select");
        var inp = document.getElementById("tso-assign-new-input");
        if (!ov || !optionNames || !optionNames.length) {
            return;
        }
        tsootcResetAssignModalButtons();
        delete ov.dataset.optionName;
        delete ov.dataset.tableName;
        ov.dataset.optionNames = JSON.stringify(optionNames);
        if (inp) {
            inp.value = "";
        }
        var cfg = window.tsootcOtcOptionsConfig || window.tsootcAdminConfig || {};
        var tpl = cfg.assignBulkSummaryTpl || '%d options selected';
        var summary = tpl.replace('%d', String(optionNames.length));
        if (optionNames.length <= 5) {
            summary += ': ' + optionNames.join(', ');
        } else {
            summary += ': ' + optionNames.slice(0, 5).join(', ') + '… (+' + String(optionNames.length - 5) + ')';
        }
        if (nm) {
            nm.textContent = summary;
        }
        tsootcFillAssignGroupSelect(sel);
        ov.classList.add("active");
    };

    window.tsootcReloadOptionsTab = function () {
        try {
            sessionStorage.setItem('tsootc_scroll', String(window.scrollY || window.pageYOffset || 0));
        } catch (e1) {
            // ignore
        }
        window.location.reload();
    };

    window.tsootcConfirmAssign = function(useNew) {
        var ov  = document.getElementById("tso-assign-overlay");
        if (!ov) return;
        var tableName  = ov.dataset.tableName || "";
        var optionName = ov.dataset.optionName || "";
        var bulkNames  = [];
        if (ov.dataset.optionNames) {
            try {
                bulkNames = JSON.parse(ov.dataset.optionNames);
            } catch (eBulk) {
                bulkNames = [];
            }
        }
        if (!Array.isArray(bulkNames)) {
            bulkNames = [];
        }
        bulkNames = bulkNames.map(function (n) { return String(n || "").trim(); }).filter(function (n) { return n !== ""; });
        var seen = {};
        bulkNames = bulkNames.filter(function (n) {
            if (seen[n]) { return false; }
            seen[n] = true;
            return true;
        });
        var pluginName = "";
        if (useNew) {
            var inp = document.getElementById("tso-assign-new-input");
            pluginName = inp ? inp.value.trim() : "";
            if (!pluginName) { inp && inp.focus(); return; }
        } else {
            var sel = document.getElementById("tso-assign-existing-select");
            pluginName = sel ? sel.value : "";
            if (!pluginName) { alert("Selecciona un grup existent o crea'n un de nou."); return; }
        }
        var btn = document.getElementById(useNew ? "tso-assign-save-new" : "tso-assign-save-existing");
        var cfg = window.tsootcOtcOptionsConfig || window.tsootcAdminConfig || {};
        var savingLabel = cfg.assignBtnSaving || "Desant...";
        if (btn) { btn.disabled = true; btn.textContent = savingLabel; }
        var action = bulkNames.length ? "tsootc_assign_options_bulk" : (tableName ? "tsootc_assign_table" : "tsootc_assign_option");
        var payload = bulkNames.length
            ? { plugin_name: pluginName, option_names: bulkNames, option_names_json: JSON.stringify(bulkNames) }
            : (tableName
                ? { table_name: tableName, plugin_name: pluginName }
                : { option_name: optionName, plugin_name: pluginName });
        tsootcPost(action, payload, function(data) {
            if (data && data.success) {
                tsootcResetAssignModalButtons();
                if (ov) {
                    ov.classList.remove("active");
                    delete ov.dataset.optionNames;
                    delete ov.dataset.optionName;
                    delete ov.dataset.tableName;
                }
                // Always reload: new plugin groups are not present in the DOM yet,
                // and options-tab cache must rebuild from the updated custom map.
                if (typeof window.tsootcReloadOptionsTab === "function") {
                    window.tsootcReloadOptionsTab();
                } else {
                    location.reload();
                }
                return;
            }
            tsootcResetAssignModalButtons();
            alert("Error desant l\'assignació.");
        });
    };

    // Marca visualment una opció com a assignada (sense recarregar)
    window.tsootcShowTableModal = function(tableName, tableInfo) {
        tsootcModalCurrentName = '';
        tsootcModalRawValue = '';
        tsootcModalHasTree = false;
        tsootcModalParsedTree = null;
        tsootcModalIsTable = true;
        tsootcModalCancelEdit();
        var editBtn = document.getElementById('tso-modal-edit-btn');
        var tabs = document.getElementById('tso-modal-view-tabs');
        var badge = document.getElementById('tso-modal-type-badge');
        if (editBtn) editBtn.style.display = 'none';
        if (tabs) tabs.style.display = 'none';
        if (badge) badge.style.display = 'none';
        var ov = document.getElementById("tso-modal-overlay");
        var nm = document.getElementById("tso-modal-name");
        if (!ov) return;
        if (nm) nm.textContent = tableName;
        tsootcRenderTableMessage(tsootcTableInspectorI18n.loading);
        // Fetch row count via AJAX
        tsootcPost("tsootc_get_option_value", {option_name: "__table__" + tableName}, function(data) {
            if (data && data.success && data.data) {
                tsootcRenderTableInspector(data.data.table_details || null, data.data.value || tableInfo);
            } else {
                tsootcRenderTableMessage(tableInfo || tsootcTableInspectorI18n.notAvailable);
            }
        });
        ov.classList.add("active");
    };

    window.tsootcMarkOptionAssigned = function(optionName, pluginName) {
        var row = document.querySelector('tr.tso-opts-row[data-name="' + CSS.escape(optionName) + '"]');
        if (!row) {
            var all = document.getElementsByClassName("tso-opts-row");
            for (var ri = 0; ri < all.length; ri++) {
                if (all[ri].dataset && all[ri].dataset.name === optionName) {
                    row = all[ri];
                    break;
                }
            }
        }
        if (!row) {
            return;
        }

        // 1. Marca manual al nom de l'opció
        var nameCell = row.querySelector(".col-name");
        if (nameCell && nameCell.querySelector(".tso-custom-badge") === null) {
            nameCell.insertAdjacentHTML(
                "beforeend",
                ' <span class="tso-custom-badge">manual</span>'
            );
        }

        // 2. Actualitzar botó assignar -> editar
        var assignBtn = row.querySelector(".btn-act.assign");
        if (assignBtn) { assignBtn.textContent = "✏️"; assignBtn.title = "Editar assignació"; }

        // 3. Actualitzar comptador del grup origen i amagar-lo si queda buit
        var oldTbody = row.closest("tbody");
        if (oldTbody) {
            var oldGrp = oldTbody.closest(".tso-plugin-group");
            if (oldGrp) {
                var oldCount = oldGrp.querySelectorAll("tbody tr.tso-opts-row").length - 1;
                var oldMeta = oldGrp.querySelector(".grp-meta");
                if (oldMeta) oldMeta.textContent = oldMeta.textContent.replace(/^\d+/, oldCount);
                if (oldCount <= 0) oldGrp.style.display = "none";
            }
        }

        // 4. Moure fila al grup destí (coincidència per títol visible) o recarregar
        var targetGrp = null;
        var nameLower = String(pluginName || "").toLowerCase();
        document.querySelectorAll(".tso-plugin-group").forEach(function (grp) {
            if (targetGrp) {
                return;
            }
            var title = grp.querySelector(".tso-plugin-group-head .grp-name");
            if (!title) {
                return;
            }
            var titleText = String(title.textContent || "").replace(/\s+/g, " ").trim().toLowerCase();
            if (titleText === nameLower || titleText.indexOf(nameLower) !== -1 || nameLower.indexOf(titleText) !== -1) {
                targetGrp = grp;
            }
        });
        if (targetGrp) {
            var targetTbody = targetGrp.querySelector("tbody");
            if (targetTbody) {
                targetTbody.appendChild(row);
                var newCount = targetGrp.querySelectorAll("tbody tr.tso-opts-row").length;
                var newMeta = targetGrp.querySelector(".grp-meta");
                if (newMeta) {
                    newMeta.textContent = newMeta.textContent.replace(/^\d+/, String(newCount));
                }
                targetGrp.style.display = "";
            }
        }
    };
})();
