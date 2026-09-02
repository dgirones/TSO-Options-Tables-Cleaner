(function () {
    'use strict';

    function tsootcOverlayEl(id) {
        return id ? document.getElementById(id) : null;
    }

    window.tsootcOpenOverlay = function (id) {
        var el = tsootcOverlayEl(id);
        if (!el) {
            return;
        }
        var root = document.getElementById('tso-modals-root');
        if (root) {
            root.removeAttribute('hidden');
        }
        el.removeAttribute('hidden');
        el.setAttribute('aria-hidden', 'false');
        el.classList.add('active');
    };

    window.tsootcCloseOverlay = function (id) {
        var el = tsootcOverlayEl(id);
        if (!el) {
            return;
        }
        el.classList.remove('active');
        el.setAttribute('hidden', 'hidden');
        el.setAttribute('aria-hidden', 'true');
        var root = document.getElementById('tso-modals-root');
        if (root) {
            var anyOpen = root.querySelector('.tso-overlay.active');
            if (!anyOpen) {
                root.setAttribute('hidden', 'hidden');
            }
        }
    };

    function tsootcInitOverlays() {
        ['tso-rename-overlay', 'tso-modal-overlay', 'tso-assign-overlay'].forEach(function (id) {
            window.tsootcCloseOverlay(id);
        });
    }

    function closest(el, selector) {
        return el && el.closest ? el.closest(selector) : null;
    }

    document.addEventListener('click', function (e) {
        var target = e.target;

        if (closest(target, '[data-tso-stop-propagation="form"]')) {
            e.stopPropagation();
        }

        var overlay = closest(target, '[data-tso-overlay-dismiss]');
        if (overlay && target === overlay) {
            if (window.tsootcCloseOverlay) {
                window.tsootcCloseOverlay(overlay.id);
            } else {
                overlay.classList.remove('active');
                overlay.setAttribute('hidden', 'hidden');
            }
            if (overlay.id === 'tso-assign-overlay' && window.tsootcResetAssignModalButtons) {
                window.tsootcResetAssignModalButtons();
            }
            return;
        }

        var restoreShow = closest(target, '[data-tso-restore-show]');
        if (restoreShow) {
            var showId = restoreShow.getAttribute('data-tso-restore-show');
            var showPanel = showId ? document.getElementById(showId) : null;
            if (showPanel) {
                showPanel.classList.remove('tso-u-hidden');
                showPanel.classList.add('is-visible');
            }
            return;
        }

        var restoreHide = closest(target, '[data-tso-restore-hide]');
        if (restoreHide) {
            var hideId = restoreHide.getAttribute('data-tso-restore-hide');
            var hidePanel = hideId ? document.getElementById(hideId) : null;
            if (hidePanel) {
                hidePanel.classList.add('tso-u-hidden');
                hidePanel.classList.remove('is-visible');
            }
            return;
        }

        var btn = closest(target, '[data-tso-click]');
        if (!btn) {
            return;
        }

        if (btn.getAttribute('data-tso-stop-propagation') === '1') {
            e.stopPropagation();
        }

        var action = btn.getAttribute('data-tso-click');
        switch (action) {
            case 'run-optimize':
                if (window.tsootcRunOptimize) {
                    window.tsootcRunOptimize();
                }
                break;
            case 'save-autoclean':
                if (window.tsootcSaveAutoclean) {
                    window.tsootcSaveAutoclean();
                }
                break;
            case 'al-toggle':
                if (window.tsootcAlToggle) {
                    window.tsootcAlToggle();
                }
                break;
            case 'al-grp-toggle':
                if (window.tsootcAlGrpToggle) {
                    window.tsootcAlGrpToggle(btn.getAttribute('data-tso-arg') || '');
                }
                break;
            case 'toggle-group':
                if (window.tsootcToggleGroup) {
                    window.tsootcToggleGroup(btn, e);
                }
                break;
            case 'open-rename-group':
                e.stopPropagation();
                if (window.tsootcOpenRenameGroup) {
                    window.tsootcOpenRenameGroup(btn.dataset.gkey, btn.dataset.gid, btn.dataset.gname);
                }
                break;
            case 'assign-selected':
                e.stopPropagation();
                if (window.tsootcAssignSelected) {
                    window.tsootcAssignSelected(btn.getAttribute('data-tso-form-id') || '');
                }
                break;
            case 'delete-selected':
                e.stopPropagation();
                if (window.tsootcDeleteSelected) {
                    window.tsootcDeleteSelected(btn.getAttribute('data-tso-form-id') || '');
                }
                break;
            case 'history-clear':
                if (window.tsootcHistoryClear) {
                    window.tsootcHistoryClear();
                }
                break;
            case 'rename-save':
                if (window.tsootcSaveGroupAlias) {
                    window.tsootcSaveGroupAlias();
                }
                break;
            case 'rename-reset':
                if (window.tsootcResetGroupAlias) {
                    window.tsootcResetGroupAlias();
                }
                break;
            case 'rename-close':
                if (window.tsootcCloseOverlay) {
                    window.tsootcCloseOverlay('tso-rename-overlay');
                }
                break;
            case 'modal-close':
                if (window.tsootcCloseOverlay) {
                    window.tsootcCloseOverlay('tso-modal-overlay');
                }
                break;
            case 'modal-toggle-edit':
                if (window.tsootcModalToggleEdit) {
                    window.tsootcModalToggleEdit();
                }
                break;
            case 'modal-switch-tab':
                if (window.tsootcSwitchTab) {
                    window.tsootcSwitchTab(btn.getAttribute('data-tso-tab') || 'tree');
                }
                break;
            case 'modal-copy':
                if (window.tsootcModalCopyAll) {
                    window.tsootcModalCopyAll();
                }
                break;
            case 'modal-save':
                if (window.tsootcModalSave) {
                    window.tsootcModalSave();
                }
                break;
            case 'modal-cancel-edit':
                if (window.tsootcModalCancelEdit) {
                    window.tsootcModalCancelEdit();
                }
                break;
            case 'assign-close':
                if (window.tsootcCloseOverlay) {
                    window.tsootcCloseOverlay('tso-assign-overlay');
                }
                if (window.tsootcResetAssignModalButtons) {
                    window.tsootcResetAssignModalButtons();
                }
                break;
            case 'assign-confirm':
                if (window.tsootcConfirmAssign) {
                    window.tsootcConfirmAssign(btn.getAttribute('data-tso-use-new') === '1');
                }
                break;
            default:
                break;
        }
    });

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute) {
            return;
        }
        var action = el.getAttribute('data-tso-change');
        if (!action) {
            return;
        }
        switch (action) {
            case 'filter-opts':
                if (window.tsootcFilterOpts) {
                    window.tsootcFilterOpts();
                }
                break;
            case 'history-filter':
                if (window.tsootcHistoryFilter) {
                    window.tsootcHistoryFilter();
                }
                break;
            case 'select-all':
                if (window.tsootcSelectAll) {
                    window.tsootcSelectAll(el, el.getAttribute('data-tso-form-id') || '');
                }
                break;
            default:
                break;
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute) {
            return;
        }
        var action = el.getAttribute('data-tso-input');
        if (!action) {
            return;
        }
        switch (action) {
            case 'filter-opts':
                if (window.tsootcFilterOpts) {
                    window.tsootcFilterOpts();
                }
                break;
            case 'history-filter':
                if (window.tsootcHistoryFilter) {
                    window.tsootcHistoryFilter();
                }
                break;
            default:
                break;
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute) {
            return;
        }
        var msg = form.getAttribute('data-tso-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    document.querySelectorAll('[data-bar-width]').forEach(function (bar) {
        var w = bar.getAttribute('data-bar-width');
        if (w !== null && w !== '') {
            bar.style.width = w + '%';
        }
    });

    if (document.body.classList.contains('tools_page_tso-options-tables-cleaner')) {
        tsootcInitOverlays();
    }
})();
