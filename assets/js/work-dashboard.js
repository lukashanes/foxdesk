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

        input.addEventListener('input', function () {
            try {
                localStorage.setItem(storageKey, input.value || '');
            } catch (_) {}
            applySearch();
        });

        applySearch();
    }

    function init() {
        document.querySelectorAll('[data-work-log-surface]').forEach(initSurface);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
