(function () {
    'use strict';
    var cfg = window.tsootcAdminConfig || {};
    var tsootcLang = cfg.lang || {};
    var tsootcCommonJs = cfg.common || {};

    function tsootcPost(action, data, cb) {
        if (typeof window.tsootcPost === 'function') {
            return window.tsootcPost(action, data, cb);
        }
        if (typeof cb === 'function') {
            cb({
                success: false,
                data: {
                    msg: (tsootcCommonJs && tsootcCommonJs.unknownLong) ? tsootcCommonJs.unknownLong : 'AJAX helper missing'
                }
            });
        }
    }

    function tsoSafeReplace(str, re, replacement) {
        return String(str == null ? '' : str).replace(re, replacement);
    }

    var allowDeleteChk = document.getElementById('tso-allow-extra-table-delete');
    var allowDeleteMsg = document.getElementById('tso-allow-extra-table-delete-msg');
    if (allowDeleteChk) {
        allowDeleteChk.addEventListener('change', function () {
            var enabled = allowDeleteChk.checked ? '1' : '0';
            allowDeleteChk.disabled = true;
            if (allowDeleteMsg) {
                allowDeleteMsg.textContent = cfg.extraTablesDeleteSaving || 'Saving…';
            }
            tsootcPost('tsootc_save_extra_table_delete_setting', { enabled: enabled }, function (data) {
                if (data && data.success) {
                    if (allowDeleteMsg && data.data && data.data.msg) {
                        allowDeleteMsg.textContent = data.data.msg;
                    }
                    setTimeout(function () {
                        location.reload();
                    }, 350);
                    return;
                }
                allowDeleteChk.disabled = false;
                allowDeleteChk.checked = !allowDeleteChk.checked;
                if (allowDeleteMsg) {
                    allowDeleteMsg.textContent = (data && data.data && data.data.msg)
                        ? data.data.msg
                        : ((tsootcCommonJs && tsootcCommonJs.unknownLong) ? tsootcCommonJs.unknownLong : 'Error');
                }
            });
        });
    }

    // ---- Selecció ----
    var selectAll  = document.getElementById("tso-tables-select-all");
    var bulkBtn    = document.getElementById("tso-tables-bulk-delete");
    var exportBtn  = document.getElementById("tso-tables-bulk-export");
    var countSpan  = document.getElementById("tso-tables-selected-count");
    var searchInput = document.getElementById("tso-tables-search");
    var filterEmpty = document.getElementById("tso-tables-filter-empty");
    var tbodyEl = document.getElementById("tso-tables-tbody");
    if (!selectAll || !bulkBtn || !exportBtn || !countSpan || !tbodyEl) return;

    var deleteUnlocked = cfg.extraTablesDeleteEnabled === true;
    var sortState = { key: "size", dir: "desc" };

    function downloadSqlFile(filename, sql) {
        var blob = new Blob([sql], {type: "application/sql;charset=utf-8"});
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url;
        a.download = filename || "tso-extra-tables-drop.sql";
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){
            URL.revokeObjectURL(url);
            a.remove();
        }, 0);
    }

    function getVisibleRows() {
        return Array.prototype.filter.call(tbodyEl.querySelectorAll("tr"), function(row) {
            return row.style.display !== "none";
        });
    }

    function getVisibleChecks() {
        return getVisibleRows().map(function(row) {
            return row.querySelector(".tso-table-chk");
        }).filter(Boolean);
    }

    function updateBulkBar() {
        var checked = getVisibleChecks().filter(function(c) { return c.checked; });
        var n = checked.length;
        var unsafe = checked.some(function(el){
            var row = el.closest("tr");
            return row && row.getAttribute("data-deletable") !== "1";
        });
        countSpan.textContent = n + (n !== 1 ? tsootcLang.tablesSelectedPl : tsootcLang.tablesSelected);
        if (unsafe && typeof tsootcCommonJs !== 'undefined') {
            countSpan.textContent += " · " + tsoSafeReplace(tsootcCommonJs.deleteBlocked, /:\s*$/, "");
        }
        bulkBtn.disabled = (!deleteUnlocked || n === 0 || unsafe);
        bulkBtn.title = !deleteUnlocked
            ? tsoSafeReplace((tsootcCommonJs && tsootcCommonJs.deleteBlocked) || '', /\n$/, '')
            : (unsafe ? tsoSafeReplace((tsootcCommonJs && tsootcCommonJs.deleteSelectionBlocked) || '', /\n$/, '') : '');
        exportBtn.disabled = (n === 0);

        var visibleChecks = getVisibleChecks();
        var visibleChecked = visibleChecks.filter(function(c) { return c.checked; });
        selectAll.indeterminate = (visibleChecked.length > 0 && visibleChecked.length < visibleChecks.length);
        selectAll.checked = (visibleChecks.length > 0 && visibleChecked.length === visibleChecks.length);
    }

    function applyTableFilter() {
        var q = searchInput ? String(searchInput.value || "").trim().toLowerCase() : "";
        var rows = tbodyEl.querySelectorAll("tr");
        var visible = 0;
        Array.prototype.forEach.call(rows, function(row) {
            var hay = row.getAttribute("data-search") || "";
            var show = !q || hay.indexOf(q) !== -1;
            row.style.display = show ? "" : "none";
            if (!show) {
                var chk = row.querySelector(".tso-table-chk");
                if (chk) {
                    chk.checked = false;
                }
            } else {
                visible++;
            }
        });
        if (filterEmpty) {
            if (q && visible === 0) {
                filterEmpty.style.display = "";
                filterEmpty.removeAttribute("hidden");
            } else {
                filterEmpty.style.display = "none";
                filterEmpty.setAttribute("hidden", "hidden");
            }
        }
        updateBulkBar();
    }

    function rowSortValue(row, key) {
        if (key === "size") {
            return parseInt(row.getAttribute("data-kb") || "0", 10) || 0;
        }
        if (key === "status") {
            return parseInt(row.getAttribute("data-sort-status") || "0", 10) || 0;
        }
        if (key === "plugin") {
            return row.getAttribute("data-sort-plugin") || "";
        }
        return row.getAttribute("data-sort-table") || "";
    }

    function compareRows(a, b, key, dir) {
        var av = rowSortValue(a, key);
        var bv = rowSortValue(b, key);
        var cmp;
        if (typeof av === "number" && typeof bv === "number") {
            cmp = av - bv;
        } else {
            cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: "base" });
        }
        if (0 === cmp && key !== "table") {
            cmp = String(rowSortValue(a, "table")).localeCompare(String(rowSortValue(b, "table")), undefined, { sensitivity: "base" });
        }
        return dir === "asc" ? cmp : -cmp;
    }

    function updateSortHeaders() {
        document.querySelectorAll(".tso-tables-sortable").forEach(function(th) {
            var key = th.getAttribute("data-sort-key");
            var active = key === sortState.key;
            th.classList.toggle("is-sorted", active);
            th.classList.toggle("is-asc", active && sortState.dir === "asc");
            th.classList.toggle("is-desc", active && sortState.dir === "desc");
            th.setAttribute("aria-sort", active ? (sortState.dir === "asc" ? "ascending" : "descending") : "none");
        });
    }

    function applyTableSort() {
        var rows = Array.prototype.slice.call(tbodyEl.querySelectorAll("tr"));
        rows.sort(function(a, b) {
            return compareRows(a, b, sortState.key, sortState.dir);
        });
        rows.forEach(function(row) {
            tbodyEl.appendChild(row);
        });
        updateSortHeaders();
    }

    function setSort(key) {
        if (sortState.key === key) {
            sortState.dir = sortState.dir === "desc" ? "asc" : "desc";
        } else {
            sortState.key = key;
            // Size defaults to largest first; text columns start A→Z.
            sortState.dir = (key === "size" || key === "status") ? "desc" : "asc";
        }
        applyTableSort();
    }

    if (searchInput) {
        searchInput.addEventListener("input", applyTableFilter);
        searchInput.addEventListener("search", applyTableFilter);
    }

    document.querySelectorAll(".tso-tables-sortable").forEach(function(th) {
        th.addEventListener("click", function() {
            var key = th.getAttribute("data-sort-key");
            if (key) {
                setSort(key);
            }
        });
        th.addEventListener("keydown", function(e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                var key = th.getAttribute("data-sort-key");
                if (key) {
                    setSort(key);
                }
            }
        });
    });

    updateSortHeaders();

    selectAll.addEventListener("change", function() {
        getVisibleChecks().forEach(function(c){ c.checked = selectAll.checked; });
        updateBulkBar();
    });

    document.querySelectorAll(".tso-table-chk").forEach(function(c){
        c.addEventListener("change", function(){
            updateBulkBar();
        });
    });

    if (tbodyEl) {
        tbodyEl.addEventListener("click", function(e){
            var actBtn = e.target.closest("[data-tso-act]");
            if (actBtn && tbodyEl.contains(actBtn)) {
                var act = actBtn.getAttribute("data-tso-act");
                if (act === "table-assign") {
                    e.preventDefault();
                    var tnAssign = actBtn.getAttribute("data-table-name");
                    if (tnAssign && typeof window.tsootcOpenTableAssign === "function") {
                        window.tsootcOpenTableAssign(tnAssign);
                    }
                    return;
                }
                if (act === "table-confirm") {
                    e.preventDefault();
                    var tnConf = actBtn.getAttribute("data-table-name");
                    var hint = actBtn.getAttribute("data-hint-label") || "";
                    if (tnConf && typeof window.tsootcConfirmTableDetection === "function") {
                        window.tsootcConfirmTableDetection(tnConf, hint, actBtn);
                    }
                    return;
                }
            }
            var vbtn = e.target.closest(".tso-table-view-btn");
            if (!vbtn || !tbodyEl.contains(vbtn)) return;
            e.preventDefault();
            var tn = vbtn.getAttribute("data-table-name");
            var ti = vbtn.getAttribute("data-table-info") || "";
            if (tn && typeof window.tsootcShowTableModal === "function") {
                window.tsootcShowTableModal(tn, ti);
            }
        });
    }

    // ---- Eliminar una taula (botó individual) ----
    document.querySelectorAll(".tso-table-del-btn").forEach(function(btn){
        btn.addEventListener("click", function(){
            var table   = btn.dataset.table;
            var rowId   = btn.dataset.row;
            var confirm_msg = btn.getAttribute("data-confirm") || "";
            if (!confirm((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteCreatesSnapshot + "\n\n" : "") + confirm_msg)) return;
            var typed = window.prompt(typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteTypeTablePrompt : "Type the exact table name to confirm deletion:", table || "");
            if (typed === null || typed.trim() !== table) {
                alert(typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteConfirmMismatch : "Deletion cancelled.");
                return;
            }
            btn.disabled = true;
            btn.textContent = "⏳";
            tsootcPost("tsootc_drop_table", {table_name: table, backup_confirmed: "1", confirm_table_name: typed.trim()}, function(data){
                if (data && data.success) {
                    var row = document.getElementById(rowId);
                    if (row) {
                        row.style.transition = "opacity .3s";
                        row.style.opacity = "0";
                    }
                    var successMsg = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteCompleted : "Table deleted.");
                    if (data.data && data.data.snapshot_file) {
                        successMsg += "\n\n" + (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteSnapshotCreated : "Restore point created: ") + data.data.snapshot_file;
                    }
                    alert(successMsg);
                    setTimeout(function(){ location.reload(); }, 250);
                } else {
                    btn.disabled = false;
                    btn.textContent = "🗑️";
                    var msg = (data && data.data && data.data.msg) ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownLong : '');
                    alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.errorDeletingTable : '') + msg);
                }
            });
        });
    });

    document.querySelectorAll(".tso-table-export-btn").forEach(function(btn){
        btn.addEventListener("click", function(){
            var table = btn.dataset.table;
            if (!table) return;
            btn.disabled = true;
            btn.textContent = "⏳";
            tsootcPost("tsootc_export_drop_sql", {table_names: table}, function(data){
                btn.disabled = false;
                btn.textContent = "🧾";
                if (data && data.success && data.data && data.data.sql) {
                    downloadSqlFile(data.data.filename, data.data.sql);
                    if (data.data.errors && data.data.errors.length > 0) {
                        alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.exportPartial : '') + data.data.errors.join(", "));
                    }
                } else {
                    var msg = (data && data.data && data.data.msg) ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownLong : '');
                    alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.errorExportingSql : '') + msg);
                }
            });
        });
    });

    document.querySelectorAll(".tso-table-backup-restore-btn").forEach(function(btn){
        btn.addEventListener("click", function(){
            var table = btn.dataset.table;
            if (!table) return;
            var prevHtml = btn.innerHTML;
            btn.disabled = true;
            btn.textContent = "⏳";
            tsootcPost("tsootc_export_table_restore_sql", {table_names: table}, function(data){
                btn.disabled = false;
                btn.innerHTML = prevHtml;
                if (data && data.success && data.data && data.data.sql) {
                    downloadSqlFile(data.data.filename, data.data.sql);
                    if (data.data.errors && data.data.errors.length > 0) {
                        alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.exportPartial : '') + data.data.errors.join(", "));
                    }
                } else {
                    var msg = (data && data.data && data.data.msg) ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownLong : '');
                    alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.errorExportingSql : '') + msg);
                }
            });
        });
    });

    // ---- Eliminar seleccionades (bulk) ----
    bulkBtn.addEventListener("click", function(){
        var checked = getVisibleChecks().filter(function(c){ return c.checked; });
        if (checked.length === 0) return;
        var unsafeRows = checked.map(function(c){
            var row = c.closest("tr");
            if (!row || row.getAttribute("data-deletable") === "1") return null;
            return (row.getAttribute("data-table") || "") + " — " + (row.getAttribute("data-delete-reason") || "");
        }).filter(Boolean);
        if (unsafeRows.length > 0) {
            alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteSelectionBlocked : '') + unsafeRows.join("\n"));
            return;
        }
        var names = checked.map(function(c){ return c.value; });
        if (!confirm((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteCreatesSnapshot + "\n\n" + tsootcCommonJs.deleteTablesBulkLabel + "\n\n" : "") + names.join("\n"))) return;
        var confirmPhrase = window.prompt(typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteTypeBulkPrompt : "Type DELETE to confirm bulk deletion:", "");
        if (confirmPhrase === null || confirmPhrase.trim().toUpperCase() !== "DELETE") {
            alert(typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteConfirmMismatch : "Deletion cancelled.");
            return;
        }
        bulkBtn.disabled = true;
        bulkBtn.textContent = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteBusy : '\u23F3');
        tsootcPost("tsootc_drop_tables_bulk", {table_names: names.join(","), backup_confirmed: "1", confirm_phrase: confirmPhrase.trim()}, function(data){
            bulkBtn.disabled = false;
            bulkBtn.textContent = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.bulkDeleteBtn : '');
            if (data && data.success) {
                data.data.deleted.forEach(function(table){
                    var row = document.querySelector("tr[data-table='" + CSS.escape(table) + "']");
                    if (row) {
                        row.style.transition = "opacity .3s";
                        row.style.opacity = "0";
                    }
                });
                var successMsg = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.bulkDeleteCompleted : "Tables deleted.");
                if (data.data && data.data.snapshot_file) {
                    successMsg += "\n\n" + (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.deleteSnapshotCreated : "Restore point created: ") + data.data.snapshot_file;
                }
                if (data.data.errors && data.data.errors.length > 0) {
                    successMsg += "\n\n" + (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.couldNotDelete : '') + data.data.errors.join(", ");
                }
                alert(successMsg);
                setTimeout(function(){ location.reload(); }, 250);
            } else {
                var msg = (data && data.data && data.data.msg) ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownLong : '');
                alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.parseErrorPrefix : 'Error: ') + msg);
            }
        });
    });

    exportBtn.addEventListener("click", function(){
        var checked = getVisibleChecks().filter(function(c){ return c.checked; });
        if (checked.length === 0) return;
        var names = checked.map(function(c){ return c.value; });
        exportBtn.disabled = true;
        exportBtn.textContent = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.exportBusy : 'Exporting SQL...');
        tsootcPost("tsootc_export_drop_sql", {table_names: names.join(",")}, function(data){
            exportBtn.disabled = false;
            exportBtn.textContent = (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.bulkExportBtn : '');
            if (data && data.success && data.data && data.data.sql) {
                downloadSqlFile(data.data.filename, data.data.sql);
                if (data.data.errors && data.data.errors.length > 0) {
                    alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.exportPartial : '') + data.data.errors.join(", "));
                }
            } else {
                var msg = (data && data.data && data.data.msg) ? data.data.msg : (typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.unknownLong : '');
                alert((typeof tsootcCommonJs !== 'undefined' ? tsootcCommonJs.errorExportingSql : '') + msg);
            }
        });
    });
})();
