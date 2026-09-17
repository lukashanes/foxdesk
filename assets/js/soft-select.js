(function () {
    'use strict';
    const config = document.querySelector('[data-soft-select-config]');
    if (!config) return;
    const fold = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    let openPicker = null;
    let sequence = 0;
    const pickers = new Map();

    function enhance(select) {
        if (select.multiple || select.size > 1 || select.dataset.softSelectReady || select.closest('[data-native-select]')) return;
        select.dataset.softSelectReady = '1';
        const wrapper = document.createElement('span');
        wrapper.className = 'soft-select';
        select.before(wrapper);
        wrapper.append(select);
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'soft-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        const caption = document.createElement('bdi');
        caption.dir = 'auto';
        trigger.append(caption);
        wrapper.append(trigger);
        const popup = document.createElement('div');
        popup.className = 'soft-select__popup';
        popup.hidden = true;
        const search = document.createElement('input');
        search.type = 'search';
        search.autocomplete = 'off';
        search.className = 'soft-select__search';
        search.placeholder = config.dataset.search;
        search.setAttribute('aria-label', config.dataset.search);
        const list = document.createElement('div');
        list.className = 'soft-select__list';
        list.id = 'soft-select-list-' + (++sequence);
        list.setAttribute('role', 'listbox');
        trigger.setAttribute('aria-controls', list.id);
        const empty = document.createElement('p');
        empty.className = 'soft-select__empty';
        empty.textContent = config.dataset.empty;
        popup.append(search, list, empty);
        (select.closest('dialog') || document.body).append(popup);
        const nativeLabel = select.getAttribute('aria-label') || Array.from(select.labels || []).map(label => {
            const copy = label.cloneNode(true);
            copy.querySelectorAll('select, .soft-select, input, textarea, button').forEach(control => control.remove());
            return copy.textContent.trim();
        }).join(' ') || select.closest('.ticket-side-row')?.querySelector('dt')?.textContent || '';
        list.setAttribute('aria-label', nativeLabel || config.dataset.search);
        select.classList.add('soft-select__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        const recentKey = /^(organization_id|assigned_to)$/.test(select.name) ? 'foxdesk:recent-select:' + config.dataset.scope + ':' + select.name : null;
        let recent = [];
        try { recent = JSON.parse(sessionStorage.getItem(recentKey) || '[]'); if (!Array.isArray(recent)) recent = []; } catch (_) {}
        function sync() {
            caption.textContent = select.selectedOptions[0]?.textContent.trim() || '—';
            trigger.disabled = select.disabled;
            trigger.setAttribute('aria-label', nativeLabel ? nativeLabel + ': ' + caption.textContent : caption.textContent);
        }
        function position() {
            const rect = trigger.getBoundingClientRect();
            const width = Math.min(Math.max(rect.width, 240), window.innerWidth - 24);
            const availableBelow = window.innerHeight - rect.bottom - 16;
            const above = availableBelow < 220 && rect.top > availableBelow;
            popup.style.width = width + 'px';
            // DOM rectangles use physical screen coordinates, independent of text direction.
            popup.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px';
            popup.style.top = (above ? Math.max(12, rect.top - Math.min(340, rect.top - 16) - 6) : rect.bottom + 6) + 'px';
            popup.style.maxHeight = Math.max(120, Math.min(340, above ? rect.top - 16 : availableBelow)) + 'px';
        }
        function close(restoreFocus = false) {
            popup.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (openPicker?.trigger === trigger) openPicker = null;
            if (restoreFocus) trigger.focus();
        }
        function render() {
            list.replaceChildren();
            const query = fold(search.value.trim());
            let options = Array.from(select.options).filter(option => !option.hidden && fold(option.textContent).includes(query));
            if (!query && recentKey) options.sort((a, b) => {
                const rank = option => recent.includes(option.value) ? recent.indexOf(option.value) : 1000 + option.index;
                return rank(a) - rank(b);
            });
            options.forEach(option => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'soft-select__option';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(option.selected));
                item.disabled = option.disabled || (option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled);
                const text = document.createElement('bdi');
                text.dir = 'auto';
                text.textContent = option.textContent.trim();
                item.append(text);
                item.addEventListener('click', () => {
                    const changed = select.value !== option.value;
                    select.value = option.value;
                    if (recentKey && option.value) {
                        recent = [option.value, ...recent.filter(value => value !== option.value)].slice(0, 5);
                        try { sessionStorage.setItem(recentKey, JSON.stringify(recent)); } catch (_) {}
                    }
                    sync(); close(true);
                    if (changed) {
                        select.dispatchEvent(new Event('input', {bubbles: true}));
                        select.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                });
                list.append(item);
            });
            empty.hidden = options.length > 0;
        }
        function open() {
            if (trigger.disabled) return;
            openPicker?.close();
            search.value = '';
            render();
            popup.hidden = false;
            position();
            trigger.setAttribute('aria-expanded', 'true');
            openPicker = {trigger, popup, close, position};
            search.focus();
        }
        trigger.addEventListener('click', () => popup.hidden ? open() : close());
        Array.from(select.labels || []).forEach(label => label.addEventListener('click', event => {
            if (event.target === trigger || trigger.contains(event.target)) return;
            event.preventDefault();
            open();
        }));
        trigger.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); open(); }
        });
        search.addEventListener('input', event => { if (!event.isComposing) render(); });
        search.addEventListener('compositionend', render);
        popup.addEventListener('keydown', event => {
            if (event.isComposing) return;
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            if (event.target === search && ['Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const items = Array.from(list.querySelectorAll('button:not(:disabled)'));
            const index = items.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
            items[next]?.focus();
        });
        select.addEventListener('change', sync);
        select.addEventListener('invalid', event => { event.preventDefault(); trigger.focus(); trigger.setAttribute('aria-invalid', 'true'); open(); });
        select.addEventListener('input', () => trigger.removeAttribute('aria-invalid'));
        select.form?.addEventListener('reset', () => setTimeout(sync, 0));
        new MutationObserver(sync).observe(select, {attributes: true, childList: true, subtree: true});
        pickers.set(select, sync);
        sync();
    }
    document.querySelectorAll('select.form-select, select.input-field, select.ticket-side-select, select.filter-select, select#header-sort, select[data-soft-select], .settings-workspace__content select').forEach(enhance);
    // Legacy editors assign native .value before revealing their dialog. That
    // does not emit change; refresh presentation only, never dispatch saves.
    new MutationObserver(records => {
        const containers = new Set(records.map(record => record.target));
        pickers.forEach((sync, select) => {
            if (Array.from(containers).some(container => container !== select && container.contains(select))) sync();
        });
    }).observe(document.body, {attributes: true, subtree: true, attributeFilter: ['class', 'hidden', 'open']});
    document.addEventListener('pointerdown', event => {
        if (openPicker && !openPicker.popup.contains(event.target) && !openPicker.trigger.contains(event.target)) openPicker.close();
    });
    document.addEventListener('focusin', event => {
        if (openPicker && !openPicker.popup.contains(event.target) && event.target !== openPicker.trigger) openPicker.close();
    });
    window.addEventListener('resize', () => openPicker?.close());
    window.addEventListener('scroll', event => { if (openPicker && !openPicker.popup.contains(event.target)) openPicker.close(); }, true);
}());
