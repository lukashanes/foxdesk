(function () {
    'use strict';

    function normalise(value) {
        return String(value || '').toLowerCase().trim();
    }

    function setHidden(element, hidden) {
        if (!element) return;
        element.classList.toggle('is-hidden', !!hidden);
        element.hidden = !!hidden;
    }

    function initSurface(surface) {
        if (surface.dataset.workSearchReady) return;
        surface.dataset.workSearchReady = "1";
        var mode = surface.getAttribute('data-work-filter-mode') || '';
        var input = surface.querySelector('[data-work-log-search]');
        var prompt = surface.querySelector('[data-work-search-prompt]');
        var empty = surface.querySelector('[data-work-search-empty]');
        var items = Array.prototype.slice.call(surface.querySelectorAll('[data-work-activity-item]'));
        var rows = Array.prototype.slice.call(surface.querySelectorAll('[data-work-team-row]'));
        var storageKey = surface.hasAttribute('data-work-team-time')
            ? 'foxdesk_work_team_search'
            : 'foxdesk_work_my_search';

        function itemMatches(item, query) {
            return normalise(item.getAttribute('data-work-search-text') || item.textContent).indexOf(query) !== -1;
        }

        function rowMatches(row, query) {
            var rowText = normalise(row.getAttribute('data-work-search-text') || row.textContent);
            var nestedItems = Array.prototype.slice.call(row.querySelectorAll('[data-work-activity-item]'));
            var nestedVisible = 0;

            nestedItems.forEach(function (item) {
                var match = itemMatches(item, query);
                setHidden(item, !match);
                if (match) nestedVisible += 1;
            });

            return rowText.indexOf(query) !== -1 || nestedVisible > 0;
        }

        function applySearch() {
            if (mode !== 'search') {
                setHidden(prompt, true);
                setHidden(empty, true);
                return;
            }

            var query = normalise(input ? input.value : '');
            var hasQuery = query.length > 0;
            var visible = 0;

            setHidden(prompt, hasQuery);

            items.forEach(function (item) {
                var match = hasQuery && itemMatches(item, query);
                setHidden(item, !match);
                if (match) visible += 1;
            });

            rows.forEach(function (row) {
                var match = hasQuery && rowMatches(row, query);
                setHidden(row, !match);
                if (match) visible += 1;
            });

            setHidden(empty, !hasQuery || visible > 0);
        }

        if (!input) {
            applySearch();
            return;
        }

        try {
            input.value = localStorage.getItem(storageKey) || '';
        } catch (_) {}

        input.addEventListener('compositionend', applySearch);
        input.addEventListener('input', function (event) {
            if (event.isComposing) return;
            try {
                localStorage.setItem(storageKey, input.value || '');
            } catch (_) {}
            applySearch();
        });

        applySearch();
    }

    // The client dashboard keeps the notification context tabs; the staff flight deck is server-rendered.
    function initContextTabs() {
        var contextTabs = Array.from(document.querySelectorAll('[data-work-context-tab]'));
        function selectContext(tab) {
            contextTabs.forEach(function (item) {
                var selected = item === tab;
                item.classList.toggle('is-active', selected);
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
            });
            document.querySelectorAll('[data-work-context-panel]').forEach(function (panel) { panel.hidden = panel.dataset.workContextPanel !== tab.dataset.workContextTab; });
        }
        contextTabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { selectContext(tab); });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') return;
                event.preventDefault();
                var next = event.key === 'Home' ? 0 : event.key === 'End' ? contextTabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + contextTabs.length) % contextTabs.length;
                selectContext(contextTabs[next]); contextTabs[next].focus();
            });
        });
        document.querySelectorAll('[data-work-context-panel]').forEach(function (panel) {
            var events = Array.from(panel.querySelectorAll('[data-work-event]'));
            var empty = panel.querySelector('[data-work-event-empty]');
            if (!empty) return;
            function renderEvents() {
                var eligible = events.filter(function (row) { return panel.dataset.workContextPanel !== 'attention' || !row.classList.contains('is-reviewed'); });
                events.forEach(function (row) { row.hidden = true; });
                var visibleEvents = eligible.slice(0, 6);
                visibleEvents.forEach(function (row) { row.hidden = false; });
                panel.classList.toggle('work-context-panel--filled', visibleEvents.length === 6);
                empty.hidden = eligible.length > 0;
                if (panel.dataset.workContextPanel === 'attention') {
                    var badge = document.querySelector('[data-work-context-tab="attention"] .work-context-tabs__count');
                    if (badge) {
                        badge.textContent = String(eligible.length);
                        badge.setAttribute('aria-label', String(eligible.length));
                    }
                }
            }
            document.addEventListener('work-events-updated', renderEvents);
            renderEvents();
        });
    }

    function init() {
        initContextTabs();
        document.querySelectorAll('[data-work-log-surface]').forEach(initSurface);
    }

    document.addEventListener('foxdesk:work-updated', function () {
        document.querySelectorAll('[data-work-log-surface]').forEach(initSurface);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// Chart tooltip: the hovered day shows its hours above the bar.
(function () {
    'use strict';
    function init() {
        var plot = document.querySelector('[data-fdd-chart-plot]');
        if (!plot) return;
        var tip = plot.querySelector('[data-fdd-chart-tip]');
        var value = tip ? tip.querySelector('[data-fdd-tip-value]') : null;
        var label = tip ? tip.querySelector('[data-fdd-tip-label]') : null;
        var days = plot.querySelectorAll('[data-fdd-day]');
        if (!tip || !days.length) return;
        var current = null;
        function show(day) {
            if (current && current !== day) current.classList.remove('is-hover');
            current = day;
            day.classList.add('is-hover');
            value.textContent = day.getAttribute('data-value') || '';
            label.textContent = day.getAttribute('data-label') || '';
            var rect = plot.getBoundingClientRect();
            tip.style.insetInlineStart = (parseFloat(day.getAttribute('data-center')) / 100 * rect.width) + 'px';
            tip.style.top = (parseFloat(day.getAttribute('data-top')) / 100 * rect.height) + 'px';
            tip.hidden = false;
        }
        function hide() {
            if (current) current.classList.remove('is-hover');
            current = null;
            tip.hidden = true;
        }
        days.forEach(function (day) {
            day.addEventListener('focus', function () { show(day); });
            day.setAttribute('tabindex', '0');
        });
        // Anywhere over the plot picks the nearest day, so the whole column is a target.
        var centers = Array.prototype.map.call(days, function (day) { return parseFloat(day.getAttribute('data-center')) || 0; });
        function nearest(clientX) {
            var rect = plot.getBoundingClientRect();
            if (!rect.width) return null;
            var pct = (clientX - rect.left) / rect.width * 100;
            var best = 0;
            for (var i = 1; i < centers.length; i++) {
                if (Math.abs(centers[i] - pct) < Math.abs(centers[best] - pct)) best = i;
            }
            return days[best];
        }
        plot.addEventListener('pointermove', function (event) {
            var day = nearest(event.clientX);
            if (day && day !== current) show(day);
        });
        plot.addEventListener('pointerleave', hide);
        plot.addEventListener('mouseleave', hide);
        hide();
        plot.addEventListener('focusout', function (event) { if (!plot.contains(event.relatedTarget)) hide(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/* Lower panels: on desktop they have a fixed height and no scrollbar, so the
   rows that would not fit are hidden, and a day label goes with its rows. */
(function () {
    'use strict';
    var panels = [];
    function fit() {
        panels.forEach(function (panel) {
            var rows = Array.prototype.slice.call(panel.querySelectorAll('.fdd-feed__group, .fdd-feed__item, .fdd-recent__row'));
            if (!rows.length) return;
            rows.forEach(function (row) { row.removeAttribute('data-fdd-trimmed'); });
            if (getComputedStyle(panel).overflowY !== 'hidden') return;
            // One pixel of slack keeps sub-pixel rows from peeking past the padding.
            var limit = panel.getBoundingClientRect().bottom - parseFloat(getComputedStyle(panel).paddingBottom) - 1;
            var cut = false;
            rows.forEach(function (row) {
                if (cut || row.getBoundingClientRect().bottom > limit) { cut = true; row.setAttribute('data-fdd-trimmed', ''); }
            });
            // Hiding rows can nudge the layout; drop the last row until it fits.
            var visible = rows.filter(function (row) { return !row.hasAttribute('data-fdd-trimmed'); });
            while (visible.length && visible[visible.length - 1].getBoundingClientRect().bottom > limit) {
                visible.pop().setAttribute('data-fdd-trimmed', '');
            }
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                if (!row.classList.contains('fdd-feed__group') || row.hasAttribute('data-fdd-trimmed')) continue;
                var next = rows[i + 1];
                if (!next || next.hasAttribute('data-fdd-trimmed') || next.classList.contains('fdd-feed__group')) row.setAttribute('data-fdd-trimmed', '');
            }
        });
    }
    function init() {
        panels = Array.prototype.slice.call(document.querySelectorAll('.fdd-low > *'));
        if (!panels.length) return;
        fit();
        var timer = null;
        function queue() { clearTimeout(timer); timer = setTimeout(fit, 60); }
        window.addEventListener('resize', queue);
        window.addEventListener('load', fit);
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
        if (typeof ResizeObserver === 'function') {
            var observer = new ResizeObserver(queue);
            panels.forEach(function (panel) { observer.observe(panel); });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
