(function () {
    'use strict';
    const root = document.querySelector('[data-profile-workspace]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[data-profile-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-profile-panel]'));
    const force = root.querySelector('[data-profile-force]')?.dataset.profileForce;
    function activate(useForce = false) {
        const anchor = location.hash.slice(1);
        const mapped = anchor === 'two-factor-section' ? 'security' : anchor === 'api-access' ? 'integrations' : anchor;
        const key = useForce === true && force ? force : tabs.some(tab => tab.dataset.profileTab === mapped) ? mapped : 'personal';
        tabs.forEach(tab => tab.setAttribute('aria-current', tab.dataset.profileTab === key ? 'page' : 'false'));
        panels.forEach(panel => { panel.hidden = panel.dataset.profilePanel !== key; });
    }
    window.addEventListener('hashchange', activate);
    activate(true);
}());
