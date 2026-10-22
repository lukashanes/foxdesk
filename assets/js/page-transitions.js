/* Partial Work navigation. Other pages retain native navigation without forced fades or delays. */
(function () {
    'use strict';
    var current = new URL(location.href);
    if (current.searchParams.get('page') !== 'work' || !document.querySelector('[data-work-time-overview]')) return;
    var regions = ['[data-workspace-queue-surface]', '[data-work-time-overview]', '[data-work-user-activity]', '[data-work-team-time]'];
    var keys = ['period', 'time_scope', 'from_date', 'to_date', 'queue', 'my_activity', 'team_activity'];
    var running = false, pending = null, revision = 0;
    var historyKey = 'foxdeskWorkNavigation';
    // DOM diagnostics make it possible to verify that a filter change kept the same document.
    document.body.dataset.workNavigation = 'partial';
    document.body.dataset.workDocumentId = window.crypto && typeof window.crypto.randomUUID === 'function'
        ? window.crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
    function initialUrl() {
        var url = new URL(location.href);
        var selected = document.querySelector('.work-period-switch .is-active');
        var form = document.querySelector('.work-custom-period');
        url.searchParams.set('period', selected ? new URL(selected.href).searchParams.get('period') : 'custom');
        if (form) {
            url.searchParams.set('time_scope', form.querySelector('[name="time_scope"]').value);
            if (url.searchParams.get('period') === 'custom') {
                ['from_date', 'to_date'].forEach(function (name) { url.searchParams.set(name, form.querySelector('[name="' + name + '"]').value); });
            }
        }
        var queue = document.querySelector('[data-work-active-key]');
        if (queue) url.searchParams.set('queue', queue.dataset.workActiveKey);
        [['my_activity', regions[2]], ['team_activity', regions[3]]].forEach(function (item) {
            var node = document.querySelector(item[1]);
            if (node) url.searchParams.set(item[0], node.dataset.workFilterMode);
        });
        return url;
    }
    current = initialUrl();
    var requested = current;
    function state(scroll) { return Object.assign({}, history.state || {}, {foxdeskWorkNavigation: {scroll:scroll}}); }
    history.replaceState(state(window.scrollY), '', current.href);
    function eligible(url) {
        if (url.origin !== location.origin || url.pathname !== location.pathname || url.searchParams.get('page') !== 'work') return false;
        return Array.from(url.searchParams.keys()).every(function (key) { return keys.concat(['page']).includes(key); });
    }
    function canonical(url) {
        keys.forEach(function (key) { if (!url.searchParams.has(key) && requested.searchParams.has(key)) url.searchParams.set(key, requested.searchParams.get(key)); });
        if (url.searchParams.get('period') !== 'custom') { url.searchParams.delete('from_date'); url.searchParams.delete('to_date'); }
        return url;
    }
    function changedRegions(url, all) {
        if (all) return regions.filter(function (selector) { return document.querySelector(selector); });
        var changed = keys.filter(function (key) { return url.searchParams.get(key) !== current.searchParams.get(key); });
        var selectors = [];
        if (changed.includes('queue')) selectors.push(regions[0]);
        if (changed.some(function (key) { return ['period','time_scope','from_date','to_date'].includes(key); })) selectors.push(regions[1], regions[3]);
        if (changed.includes('my_activity')) selectors.push(regions[2]);
        if (changed.includes('team_activity')) selectors.push(regions[3]);
        return Array.from(new Set(selectors)).filter(function (selector) { return document.querySelector(selector); });
    }
    function busy(selectors, value) {
        selectors.forEach(function (selector) {
            var node = document.querySelector(selector);
            if (node) { if (value) node.setAttribute('aria-busy','true'); else node.removeAttribute('aria-busy'); }
        });
    }
    async function drain() {
        if (running) return;
        running = true;
        while (pending) {
            var job = pending; pending = null;
            var selectors = changedRegions(job.url, job.pop);
            busy(selectors, true);
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 15000);
            try {
                // Serialize reads that remember preferences, so older requests cannot save after a newer choice.
                var response = await fetch(job.url.href, {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{'X-FoxDesk-Partial':'work'}});
                var html = await response.text();
                if (job.revision !== revision) continue;
                if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Navigation response unavailable');
                var doc = new DOMParser().parseFromString(html, 'text/html');
                if (!doc.querySelector('[data-work-time-overview]') || !doc.querySelector('#sidebar')) throw new Error('Session or page changed');
                if (doc.documentElement.lang !== document.documentElement.lang
                    || doc.querySelector('#ticket-workflow-script')?.dataset.scope !== document.querySelector('#ticket-workflow-script')?.dataset.scope) throw new Error('Workspace or locale changed');
                var replacements = selectors.map(function (selector) {
                    var next = doc.querySelector(selector), old = document.querySelector(selector);
                    if (!next || !old) throw new Error('Dashboard region unavailable');
                    next.querySelectorAll('script:not([type="application/json"])').forEach(function (script) { script.remove(); });
                    return {old:old, next:document.importNode(next,true)};
                });
                document.dispatchEvent(new CustomEvent('foxdesk:work-before-update',{detail:{selectors:selectors}}));
                replacements.forEach(function (item) {
                    item.old.querySelectorAll('input').forEach(function (input) { if (input._flatpickr) input._flatpickr.destroy(); });
                    item.old.replaceWith(item.next);
                });
                if (!job.pop && current.href !== job.url.href) {
                    history.replaceState(state(job.scroll), '', current.href);
                    history.pushState(state(job.scroll), '', job.url.href);
                }
                current = job.url;
                document.dispatchEvent(new CustomEvent('foxdesk:work-updated',{detail:{selectors:selectors}}));
                if (job.focus) {
                    var candidates = selectors.flatMap(function (selector) { return Array.from(document.querySelector(selector).querySelectorAll('a,button,input')); });
                    var focus = candidates.find(function (node) { return job.focus.name ? node.name === job.focus.name : node.textContent.trim() === job.focus.text; });
                    if (focus) focus.focus({preventScroll:true});
                }
                window.scrollTo({top:job.scroll,left:0,behavior:'instant'});
            } catch (_) {
                if (job.revision === revision) { location.assign(job.url.href); return; }
            } finally {
                clearTimeout(timeout); busy(selectors, false);
            }
        }
        running = false;
    }
    function navigate(url, pop, scroll) {
        var focused = document.activeElement;
        requested = pop ? url : canonical(url);
        pending = {url:requested,pop:pop,scroll:scroll == null ? window.scrollY : scroll,revision:++revision,
            focus:focused && focused.closest('[data-work-time-overview],[data-work-log-surface],[data-workspace-queue-surface]') ? {name:focused.name,text:focused.textContent.trim()} : null};
        drain();
    }
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        if (!link.closest('[data-work-time-overview],[data-work-log-surface],[data-workspace-queue-surface]')) return;
        var url = new URL(link.href);
        if (!eligible(url)) return;
        var key = link.hasAttribute('data-work-queue-key') ? 'queue'
            : link.closest('.work-period-switch') ? 'period'
            : link.closest('.work-scope-switch') ? 'time_scope'
            : link.closest('[data-work-user-activity]') ? 'my_activity'
            : link.closest('[data-work-team-time]') ? 'team_activity' : null;
        if (!key) return;
        var value = key === 'queue' ? link.dataset.workQueueKey : url.searchParams.get(key);
        if (!value) return;
        url = new URL(requested.href); url.searchParams.set(key,value);
        event.preventDefault(); navigate(url,false);
    });
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('.work-custom-period') || event.defaultPrevented || event.isComposing) return;
        var url = new URL(requested.href), values = new FormData(form);
        ['period','from_date','to_date'].forEach(function (key) { url.searchParams.set(key,values.get(key)); });
        if (!eligible(url) || !form.checkValidity()) return;
        event.preventDefault(); navigate(url,false);
    });
    window.addEventListener('popstate', function (event) {
        var url = new URL(location.href);
        if (event.state && event.state[historyKey] && eligible(url)) navigate(url,true,event.state[historyKey].scroll);
        else location.reload();
    });
})();
