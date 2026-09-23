(function () {
    'use strict';
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
    document.querySelectorAll('[data-settings-workspace]').forEach(workspace => {
        const input = workspace.querySelector('[data-settings-search]');
        if (!input) return;
        const links = Array.from(workspace.querySelectorAll('[data-settings-destination]'));
        const results = workspace.querySelector('[data-settings-search-results]');
        let index = [];
        let sections = [];
        let loading = null;
        try { sections = JSON.parse(workspace.querySelector('[data-settings-section-index]')?.textContent || '[]'); } catch (_) {}
        function indexRenderedFields(doc, pageUrl) {
            const fields = doc.querySelectorAll('.settings-workspace__content label');
            fields.forEach(field => {
                if (field.closest('[hidden],[aria-hidden="true"]')) return;
                const label = field.textContent.replace(/\s+/g, ' ').trim();
                if (!label) return;
                const target = new URL(pageUrl, location.href);
                target.searchParams.set('settings_find', label);
                if (!index.some(item => item.url === target.href)) index.push({label, url: target.href});
            });
        }
        indexRenderedFields(document, location.href);
        function loadFieldIndex() {
            if (loading) return loading;
            const queue = sections.slice();
            results?.setAttribute('aria-busy', 'true');
            async function worker() {
                while (queue.length) {
                    const target = new URL(queue.shift(), location.href);
                    if (target.origin !== location.origin) continue;
                    try {
                        const response = await fetch(target, {credentials: 'same-origin', headers: {'Accept': 'text/html'}});
                        if (!response.ok || new URL(response.url).origin !== location.origin) continue;
                        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                        // Never index login pages, raw source, field values or hidden credentials.
                        if (doc.querySelector('[data-settings-workspace]')) indexRenderedFields(doc, target);
                    } catch (_) { /* Navigation links and the current page remain searchable. */ }
                }
            }
            loading = Promise.all([worker(), worker()]).finally(() => {
                results?.removeAttribute('aria-busy');
                if (normalize(input.value)) search();
            });
            return loading;
        }
        function search() {
            const query = normalize(input.value);
            if (query) loadFieldIndex();
            links.forEach(link => {
                link.hidden = !normalize(link.dataset.settingsSearchText).includes(query);
            });
            if (results) {
                results.replaceChildren();
                results.hidden = !query;
                index.filter(item => normalize(item.label).includes(query)).forEach(item => {
                    const link = document.createElement('a');
                    link.href = item.url;
                    link.textContent = item.label;
                    results.append(link);
                });
            }
        }
        input.addEventListener('input', event => { if (!event.isComposing) search(); });
        input.addEventListener('compositionend', search);
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') { input.value = ''; search(); }
        });
        const destination = normalize(new URLSearchParams(location.search).get('settings_find'));
        if (destination) {
            const label = Array.from(workspace.querySelectorAll('.settings-workspace__content label')).find(item => normalize(item.textContent) === destination);
            if (label) {
                for (let parent = label.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
                label.classList.add('settings-search-match');
                label.scrollIntoView({block: 'center'});
                const field = label.control || label.querySelector('input,select,textarea');
                const focusTarget = field?.closest('.soft-select')?.querySelector('button') || field;
                focusTarget?.focus({preventScroll: true});
            }
        }

        const dirtyForms = new Set();
        let navigating = false;
        workspace.querySelectorAll('.settings-workspace__content form').forEach(form => {
            if (form.method.toLowerCase() !== 'post') return;
            const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]),select,textarea'));
            if (!fields.length) return;
            const snapshot = () => JSON.stringify(fields.map(field => [field.name, field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.type === 'file' ? Array.from(field.files).map(file => [file.name, file.size, file.lastModified]) : field.value]));
            const initial = snapshot();
            const saves = Array.from(form.querySelectorAll('button[type="submit"][name^="save_"]')).filter(button => !button.closest('[data-no-dirty-bar]'));
            let actions = null;
            if (saves.length === 1) {
                actions = document.createElement('div');
                actions.className = 'settings-form-actions';
                actions.hidden = true;
                const cancel = document.createElement('button');
                cancel.type = 'reset';
                cancel.className = 'btn btn-secondary btn-sm';
                cancel.textContent = workspace.querySelector('[data-settings-cancel-label]')?.dataset.settingsCancelLabel || '';
                form.append(actions);
                actions.append(cancel, saves[0]);
            }
            function update() {
                const dirty = snapshot() !== initial;
                if (dirty) dirtyForms.add(form); else dirtyForms.delete(form);
                form.classList.toggle('is-dirty', dirty);
                if (actions) actions.hidden = !dirty;
            }
            form.addEventListener('input', event => { if (!event.isComposing) update(); });
            form.addEventListener('compositionend', update);
            form.addEventListener('change', update);
            form.addEventListener('reset', () => setTimeout(update, 0));
            form.addEventListener('submit', event => {
                // Never suppress the guard for a canceled/invalid submit or for other dirty forms.
                queueMicrotask(() => { navigating = !event.defaultPrevented && form.checkValidity() && Array.from(dirtyForms).every(item => item === form); });
            });
        });
        window.addEventListener('beforeunload', event => {
            if (dirtyForms.size && !navigating) { event.preventDefault(); event.returnValue = ''; }
        });
    });
}());
