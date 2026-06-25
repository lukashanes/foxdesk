/**
 * FoxDesk - Keyboard Shortcuts & Command Palette
 *
 * Shortcuts:
 *   Cmd/Ctrl+K  — Open command palette
 *   N           — New ticket (when not in input)
 *   /           — Focus search (when not in input)
 *   G then D    — Go to Dashboard
 *   G then A    — Go to Analytics
 *   G then T    — Go to Tickets
 *   G then R    — Go to Reports
 *   Esc         — Close command palette / modal
 *   ?           — Show shortcuts help
 */

(function() {
    'use strict';

    var cfg = window.appConfig || {};
    var pendingG = false;
    var gTimeout = null;

    // Helpers
    function isInput(el) {
        if (!el) return false;
        var tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    function navigateTo(page, params) {
        var url = 'index.php?page=' + page;
        if (params) {
            for (var k in params) {
                url += '&' + k + '=' + encodeURIComponent(params[k]);
            }
        }
        window.location.href = url;
    }

    /* =============================
       Command Palette
       ============================= */

    var paletteEl = null;
    var paletteInput = null;
    var paletteResults = null;
    var paletteVisible = false;
    var paletteItems = [];
    var paletteSelected = 0;
    var searchTimeout = null;

    function buildPalette() {
        if (paletteEl) return;

        // Backdrop
        paletteEl = document.createElement('div');
        paletteEl.id = 'command-palette';
        paletteEl.className = 'cmd-palette-backdrop';
        paletteEl.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:flex-start;justify-content:center;padding-top:20vh;';
        paletteEl.addEventListener('click', function(e) {
            if (e.target === paletteEl) closePalette();
        });

        // Dialog
        var dialog = document.createElement('div');
        dialog.style.cssText = 'width:min(560px,90vw);border-radius:12px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);';
        dialog.style.background = 'var(--surface-primary, #fff)';
        dialog.style.border = '1px solid var(--border-light, #e2e8f0)';

        // Search input
        var inputWrap = document.createElement('div');
        inputWrap.style.cssText = 'padding:12px 16px;border-bottom:1px solid var(--border-light, #e2e8f0);display:flex;align-items:center;gap:8px;';

        var searchIcon = document.createElement('span');
        searchIcon.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
        searchIcon.style.cssText = 'color:var(--text-muted);flex-shrink:0;';

        paletteInput = document.createElement('input');
        paletteInput.type = 'text';
        paletteInput.placeholder = 'Search tickets, clients, history...';
        paletteInput.style.cssText = 'flex:1;border:none;outline:none;font-size:15px;background:transparent;color:var(--text-primary);';
        paletteInput.addEventListener('input', onPaletteInput);
        paletteInput.addEventListener('keydown', onPaletteKeydown);

        var kbdHint = document.createElement('kbd');
        kbdHint.textContent = 'ESC';
        kbdHint.style.cssText = 'font-size:11px;padding:2px 6px;border-radius:4px;background:var(--surface-secondary, #f1f5f9);color:var(--text-muted);border:1px solid var(--border-light);';

        inputWrap.appendChild(searchIcon);
        inputWrap.appendChild(paletteInput);
        inputWrap.appendChild(kbdHint);

        // Results
        paletteResults = document.createElement('div');
        paletteResults.style.cssText = 'max-height:340px;overflow-y:auto;padding:8px;';

        dialog.appendChild(inputWrap);
        dialog.appendChild(paletteResults);
        paletteEl.appendChild(dialog);
        document.body.appendChild(paletteEl);
    }

    function getDefaultItems() {
        var items = [
            { type: 'nav', label: 'Dashboard', desc: 'Open your main workspace dashboard', icon: '\u2302', action: function() { navigateTo('work'); } },
            { type: 'nav', label: 'Analytics', desc: 'View analytics dashboard', icon: 'A', action: function() { navigateTo('dashboard'); } },
            { type: 'nav', label: 'Tickets', desc: 'View all tickets', icon: '\uD83C\uDF9F', action: function() { navigateTo('tickets'); } },
            { type: 'action', label: 'New Ticket', desc: 'Create a new ticket', icon: '\u2795', action: function() { navigateTo('new-ticket'); } },
            { type: 'nav', label: 'My Profile', desc: 'Edit your profile', icon: '\uD83D\uDC64', action: function() { navigateTo('profile'); } }
        ];
        if (cfg.isStaff) {
            items.push({ type: 'nav', label: 'Time Reports', desc: 'View time reports', icon: '\uD83D\uDCCA', action: function() { navigateTo('admin', {section:'reports'}); } });
            items.push({ type: 'nav', label: 'Settings', desc: 'System settings', icon: '\u2699', action: function() { navigateTo('admin', {section:'settings'}); } });
            items.push({ type: 'nav', label: 'Users', desc: 'Manage users', icon: '\uD83D\uDC65', action: function() { navigateTo('admin', {section:'users'}); } });
            items.push({ type: 'nav', label: 'Organizations', desc: 'Manage companies', icon: '\uD83C\uDFE2', action: function() { navigateTo('admin', {section:'organizations'}); } });
        }
        items.push({ type: 'action', label: 'Toggle Dark Mode', desc: 'Switch light/dark theme', icon: '\uD83C\uDF13', action: function() { if (typeof toggleTheme === 'function') toggleTheme(); } });
        return items;
    }

    function getItemTypeLabel(item) {
        if (item.type === 'ticket') return 'ticket';
        if (item.type === 'client') return 'client';
        if (item.type === 'contact') return 'contact';
        if (item.type === 'report') return 'report';
        if (item.type === 'action') return 'action';
        return 'go to';
    }

    function renderPaletteSection(title) {
        var header = document.createElement('div');
        header.className = 'cmd-palette-section';
        header.textContent = title;
        header.style.cssText = 'padding:10px 12px 5px;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-muted);';
        paletteResults.appendChild(header);
    }

    function renderPaletteItems(items) {
        paletteItems = items;
        paletteSelected = 0;
        paletteResults.innerHTML = '';

        if (!items.length) {
            var empty = document.createElement('div');
            empty.textContent = 'No results found';
            empty.style.cssText = 'padding:16px;text-align:center;color:var(--text-muted);font-size:13px;';
            paletteResults.appendChild(empty);
            return;
        }

        var previousSection = '';
        items.forEach(function(item, i) {
            if (item.section && item.section !== previousSection) {
                renderPaletteSection(item.section);
                previousSection = item.section;
            }

            var row = document.createElement('div');
            row.className = 'cmd-palette-item';
            row.dataset.index = i;
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:14px;transition:background 0.1s;';
            if (i === 0) row.style.background = 'var(--surface-secondary, #f1f5f9)';

            row.addEventListener('click', function() { executeItem(item); });
            row.addEventListener('mouseenter', function() {
                paletteSelected = i;
                highlightItem(i);
            });

            var icon = document.createElement('span');
            icon.textContent = item.icon || '\u25CB';
            icon.style.cssText = 'font-size:16px;width:24px;text-align:center;flex-shrink:0;';

            var texts = document.createElement('div');
            texts.style.cssText = 'flex:1;min-width:0;';

            var label = document.createElement('div');
            label.style.cssText = 'font-weight:500;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            label.textContent = item.label;

            var desc = document.createElement('div');
            desc.style.cssText = 'font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            desc.textContent = item.desc || '';

            texts.appendChild(label);
            if (item.desc) texts.appendChild(desc);

            var badge = document.createElement('span');
            badge.style.cssText = 'font-size:10px;padding:2px 6px;border-radius:4px;background:var(--surface-secondary, #f1f5f9);color:var(--text-muted);flex-shrink:0;text-transform:uppercase;';
            badge.textContent = getItemTypeLabel(item);

            row.appendChild(icon);
            row.appendChild(texts);
            row.appendChild(badge);
            paletteResults.appendChild(row);
        });
    }

    function getGlobalSearchSectionLabels() {
        return {
            open_tickets: 'Open tickets',
            done_tickets: 'Done tickets',
            archived_tickets: 'Archived tickets',
            clients: 'Clients',
            contacts: 'Contacts',
            reports: 'Reports'
        };
    }

    function resultToPaletteItem(result, sectionLabel) {
        var badge = result.status_label || result.type_label || '';
        var title = result.title || '';
        var subtitle = result.subtitle || '';
        return {
            type: result.type || 'ticket',
            label: title,
            desc: subtitle || badge,
            section: sectionLabel,
            icon: result.type === 'client' ? 'C' : (result.type === 'contact' ? '@' : (result.type === 'report' ? 'R' : '#')),
            action: function() {
                if (result.url) {
                    window.location.href = result.url;
                }
            }
        };
    }

    function globalSearchToPaletteItems(data) {
        var items = [];
        var labels = getGlobalSearchSectionLabels();
        var sections = data && data.sections ? data.sections : {};

        ['open_tickets', 'done_tickets', 'archived_tickets', 'clients', 'contacts', 'reports'].forEach(function(key) {
            var section = sections[key];
            if (!section || !Array.isArray(section.items)) return;
            var label = ((section.definition && section.definition.label) || section.label || labels[key] || key);
            section.items.forEach(function(result) {
                items.push(resultToPaletteItem(result, label));
            });
        });

        return items;
    }

    function highlightItem(index) {
        var items = paletteResults.querySelectorAll('.cmd-palette-item');
        items.forEach(function(el, i) {
            el.style.background = i === index ? 'var(--surface-secondary, #f1f5f9)' : 'transparent';
        });
    }

    function executeItem(item) {
        closePalette();
        if (item && typeof item.action === 'function') {
            item.action();
        }
    }

    function onPaletteInput() {
        var q = paletteInput.value.trim().toLowerCase();

        if (!q) {
            renderPaletteItems(getDefaultItems());
            return;
        }

        // Filter defaults
        var filtered = getDefaultItems().filter(function(item) {
            return item.label.toLowerCase().indexOf(q) !== -1 || (item.desc && item.desc.toLowerCase().indexOf(q) !== -1);
        });

        // Search the global index via API (debounced)
        clearTimeout(searchTimeout);
        if (q.length >= 2 && cfg.apiUrl) {
            searchTimeout = setTimeout(function() {
                fetch(cfg.apiUrl + '&action=global-search&q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.sections) return;
                        renderPaletteItems(globalSearchToPaletteItems(data).concat(filtered));
                    })
                    .catch(function(err) { console.warn('Command palette search failed:', err.message || err); });
            }, 300);
        }

        renderPaletteItems(filtered);
    }

    function onPaletteKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            paletteSelected = Math.min(paletteSelected + 1, paletteItems.length - 1);
            highlightItem(paletteSelected);
            var items = paletteResults.querySelectorAll('.cmd-palette-item');
            if (items[paletteSelected]) items[paletteSelected].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            paletteSelected = Math.max(paletteSelected - 1, 0);
            highlightItem(paletteSelected);
            var items2 = paletteResults.querySelectorAll('.cmd-palette-item');
            if (items2[paletteSelected]) items2[paletteSelected].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (paletteItems[paletteSelected]) {
                executeItem(paletteItems[paletteSelected]);
            }
        } else if (e.key === 'Escape') {
            closePalette();
        }
    }

    function openPalette(initialQuery) {
        buildPalette();
        paletteEl.style.display = 'flex';
        paletteVisible = true;
        paletteInput.value = initialQuery || '';
        if (paletteInput.value.trim()) {
            onPaletteInput();
        } else {
            renderPaletteItems(getDefaultItems());
        }
        setTimeout(function() {
            paletteInput.focus();
            paletteInput.select();
        }, 50);
    }

    function bindHeaderSearchToPalette() {
        var search = document.getElementById('header-search');
        var mobileSearch = document.getElementById('mobile-header-search');

        if (search) {
            var form = search.closest('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    openPalette(search.value);
                    search.value = '';
                });
            }

            search.addEventListener('focus', function() {
                openPalette(search.value);
                search.value = '';
            });
        }

        if (mobileSearch) {
            mobileSearch.addEventListener('click', function() {
                openPalette();
            });
        }
    }

    function closePalette() {
        if (paletteEl) paletteEl.style.display = 'none';
        paletteVisible = false;
    }

    /* =============================
       Shortcuts Help Modal
       ============================= */

    function showShortcutsHelp() {
        // Delegate to help panel if it exists
        if (document.getElementById('help-panel')) {
            openHelpPanel('shortcuts');
            return;
        }
    }

    /* =============================
       Global Keydown Handler
       ============================= */

    document.addEventListener('keydown', function(e) {
        var el = document.activeElement;
        var inInput = isInput(el);

        // Cmd/Ctrl+K — Command Palette
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            if (paletteVisible) closePalette(); else openPalette();
            return;
        }

        // Escape — close things
        if (e.key === 'Escape') {
            if (paletteVisible) { closePalette(); return; }
            var helpPanel = document.getElementById('help-panel');
            if (helpPanel && helpPanel.classList.contains('open')) { closeHelpPanel(); return; }
            var helpModal = document.getElementById('shortcuts-help-modal');
            if (helpModal) { helpModal.remove(); return; }
            return;
        }

        // Don't process single-key shortcuts when in input fields
        if (inInput) return;

        // G-sequences
        if (pendingG) {
            clearTimeout(gTimeout);
            pendingG = false;
            if (e.key === 'd') { navigateTo('work'); return; }
            if (e.key === 'a') { navigateTo('dashboard'); return; }
            if (e.key === 't') { navigateTo('tickets'); return; }
            if (e.key === 'r') { navigateTo('admin', {section:'reports'}); return; }
            if (e.key === 'o') { navigateTo('admin', {section:'organizations'}); return; }
            if (e.key === 'u') { navigateTo('admin', {section:'users'}); return; }
            if (e.key === 's') { navigateTo('admin', {section:'settings'}); return; }
            return;
        }

        if (e.key === 'g') {
            pendingG = true;
            gTimeout = setTimeout(function() { pendingG = false; }, 800);
            return;
        }

        // N — new ticket
        if (e.key === 'n' || e.key === 'N') {
            navigateTo('new-ticket');
            return;
        }

        // / — focus search
        if (e.key === '/') {
            e.preventDefault();
            var search = document.getElementById('header-search');
            if (search) search.focus(); else openPalette();
            return;
        }

        // ? — shortcuts help
        if (e.key === '?') {
            showShortcutsHelp();
            return;
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindHeaderSearchToPalette);
    } else {
        bindHeaderSearchToPalette();
    }
})();

/* =============================
   Help Panel (global functions)
   ============================= */

function openHelpPanel(tabId) {
    var panel = document.getElementById('help-panel');
    var overlay = document.getElementById('help-panel-overlay');
    if (!panel) return;
    panel.classList.add('open');
    if (overlay) overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (tabId) switchHelpTab(tabId);
    // Close sidebar on mobile
    if (window.innerWidth < 1024 && typeof setSidebarOpen === 'function') {
        setSidebarOpen(false);
    }
}

function closeHelpPanel() {
    var panel = document.getElementById('help-panel');
    var overlay = document.getElementById('help-panel-overlay');
    if (!panel) return;
    panel.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
}

function toggleHelpPanel() {
    var panel = document.getElementById('help-panel');
    if (panel && panel.classList.contains('open')) {
        closeHelpPanel();
    } else {
        openHelpPanel();
    }
}

function switchHelpTab(tabId) {
    var tabs = document.querySelectorAll('.help-panel-tab');
    var panels = document.querySelectorAll('.help-tab-content');
    tabs.forEach(function(t) { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
    panels.forEach(function(p) { p.classList.remove('active'); });
    var targetTab = document.querySelector('.help-panel-tab[data-tab="' + tabId + '"]');
    var targetPanel = document.getElementById('help-tab-' + tabId);
    if (targetTab) { targetTab.classList.add('active'); targetTab.setAttribute('aria-selected', 'true'); }
    if (targetPanel) targetPanel.classList.add('active');
}
