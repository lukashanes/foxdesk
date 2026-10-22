(function () {
    'use strict';
    document.querySelectorAll('[data-row-action-menu]').forEach(menu => {
        const trigger = document.querySelector('[popovertarget="' + CSS.escape(menu.id) + '"]');
        if (!trigger) return;
        function place() {
            const rect = trigger.getBoundingClientRect();
            const width = Math.min(220, innerWidth - 24);
            const height = menu.scrollHeight;
            const rtl = getComputedStyle(document.documentElement).direction === 'rtl';
            const start = rtl ? innerWidth - rect.right : rect.right - width;
            menu.style.insetInlineStart = Math.max(12, Math.min(innerWidth - width - 12, start)) + 'px';
            menu.style.insetBlockStart = (rect.bottom + height + 12 > innerHeight ? Math.max(12, rect.top - height - 6) : rect.bottom + 6) + 'px';
        }
        menu.addEventListener('toggle', event => {
            trigger.setAttribute('aria-expanded', String(event.newState === 'open'));
            if (event.newState === 'open') place();
        });
        menu.addEventListener('click', event => {
            if (event.target.closest('button, a') && menu.matches(':popover-open')) menu.hidePopover();
        });
        window.addEventListener('resize', () => { if (menu.matches(':popover-open')) place(); });
        document.addEventListener('scroll', event => {
            if (menu.matches(':popover-open') && !menu.contains(event.target)) menu.hidePopover();
        }, true);
    });
}());
