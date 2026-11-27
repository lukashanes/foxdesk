const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/autosave.js'), 'utf8');
function storage() {
    const data = new Map();
    return { get length() { return data.size; }, key: i => [...data.keys()][i], getItem: k => data.get(k) ?? null,
        setItem: (k, v) => data.set(k, v), removeItem: k => data.delete(k) };
}
function load(scope, localStorage, sessionStorage) {
    const field = { value: 'Private ticket title', handlers: {}, dispatchEvent() {}, addEventListener(k, fn) { this.handlers[k] = fn; } };
    const form = { handlers: {}, addEventListener(k, fn) { this.handlers[k] = fn; } };
    const document = { querySelector: selector => selector.startsWith('meta[') ? (scope ? { content: scope } : null) : selector === '#form' ? form : field };
    const window = { localStorage, sessionStorage, addEventListener() {} };
    let tick;
    vm.runInNewContext(source, { window, document, localStorage, sessionStorage, console, Date, Number, Event,
        setInterval(fn) { tick = fn; return 1; }, clearInterval() {} });
    return { draft: window.FoxDeskAutosave.create({ key: 'foxdesk_draft_new_ticket', fields: [{ name: 'title', selector: '#title', type: 'input' }] }), field, form, autosave: window.FoxDeskAutosave, tick: () => tick() };
}
test('new ticket and login load a versioned autosave script after upgrades', () => {
    for (const file of ['includes/components/new-ticket-assets.php', 'pages/login.php']) {
        const php = fs.readFileSync(require('node:path').join(__dirname, '..', file), 'utf8');
        const script = php.match(/<script src="assets\/js\/autosave\.js[^\n]+/)[0];
        assert.match(script, /\?v=<\?php[^\n]+(?:filemtime|asset_version)/,
            `${file} must invalidate the old cached autosave API and cleanup code`);
    }
});
test('drafts stay in the same account, login and browser tab', () => {
    const local = storage(), session = storage();
    const nonce = 'a'.repeat(32);
    local.setItem('foxdesk_draft_new_ticket', 'legacy private content');
    local.setItem('theme', 'dark');
    const a = load(`10:${nonce}`, local, session);
    a.draft.save();
    assert.equal(local.getItem('foxdesk_draft_new_ticket'), null);
    assert.equal(local.getItem('theme'), 'dark');
    assert.equal(session.length, 1);
    const same = load(`10:${nonce}`, local, session);
    same.field.value = '';
    assert.equal(same.draft.restore(), true);
    assert.equal(same.field.value, 'Private ticket title');
    const otherTab = load(`10:${nonce}`, local, storage());
    assert.equal(otherTab.draft.restore(), false);
    for (const scope of [`12:${nonce}`, `11:${nonce}`, `10:${'b'.repeat(32)}`, '']) {
        a.draft.save();
        const other = load(scope, local, session);
        assert.equal(other.draft.restore(), false);
        assert.equal(session.length, 0);
    }
});

test('login and migration erase legacy workflow drafts without touching preferences', () => {
    for (const scope of ['', `10:${'a'.repeat(32)}`]) {
        const local = storage(), session = storage();
        local.setItem('foxdesk_workflow_draft_v2_0-10_42', 'Private internal note');
        session.setItem('foxdesk_workflow_draft_v2_0-10_42', 'Old draft');
        local.setItem('theme', 'dark');
        load(scope, local, session);
        assert.equal(local.getItem('foxdesk_workflow_draft_v2_0-10_42'), null);
        assert.equal(session.length, 0);
        assert.equal(local.getItem('theme'), 'dark');
    }
});

test('opening a form purges expired sibling drafts in the same login', () => {
    const local = storage(), session = storage(), scope = `10:${'a'.repeat(32)}`;
    const prefix = `foxdesk_draft_v2_${scope}:`;
    const now = Date.now();
    const expired = [
        ['new-ticket', {timestamp: now - 25 * 3600000, fields: {title: 'Old customer'}}],
        ['workflow_42', {saved: now - 25 * 3600000, content: 'Old internal note'}],
        ['search', {timestamp: now - 25 * 3600000, value: 'Old customer search'}],
        ['broken-date', {timestamp: 'invalid', fields: {title: 'Customer'}}],
        ['future-date', {saved: now + 3600000, content: 'Customer'}],
        ['extra-expiry', {timestamp: now - 25 * 3600000, expires: now + 10000, fields: {title: 'Old customer'}}],
        ['workflow_42_undo', {status: 1, expires: now - 1}],
    ];
    for (const [key, value] of expired) session.setItem(prefix + key, JSON.stringify(value));
    session.setItem(prefix + 'current', JSON.stringify({timestamp: now, fields: {title: 'Current customer'}}));
    session.setItem(prefix + 'current-workflow', JSON.stringify({saved: now, content: 'Current internal note'}));
    session.setItem(prefix + 'current_undo', JSON.stringify({status: 1, expires: now + 5000}));
    session.setItem(prefix + 'foxdesk_draft_timer_alerted', JSON.stringify(now));
    session.setItem(prefix + 'broken-json', '{');
    session.setItem('theme', 'dark');
    load(scope, local, session);
    for (const [key] of expired) assert.equal(session.getItem(prefix + key), null, key);
    assert.notEqual(session.getItem(prefix + 'current'), null);
    assert.notEqual(session.getItem(prefix + 'current-workflow'), null);
    assert.notEqual(session.getItem(prefix + 'current_undo'), null);
    assert.notEqual(session.getItem(prefix + 'foxdesk_draft_timer_alerted'), null);
    assert.equal(session.getItem(prefix + 'broken-json'), null);
    assert.equal(session.getItem('theme'), 'dark');
});

test('a draft expires at the 24-hour boundary', () => {
    const local = storage(), session = storage(), scope = `10:${'a'.repeat(32)}`;
    const instance = load(scope, local, session);
    instance.draft.save();
    const key = session.key(0), data = JSON.parse(session.getItem(key));
    const now = Date.now();
    session.setItem(key, JSON.stringify({...data, timestamp: now - 86400000}));
    const originalNow = Date.now;
    try {
        Date.now = () => now;
        assert.equal(instance.draft.restore(), false);
        assert.equal(session.getItem(key), null);
    } finally {
        Date.now = originalNow;
    }
});

// Execute the actual workflow storage/event boundary before its UI enhancements.
function workflow(scope, local, session, meta = {}) {
    const phpScope = `10:${'a'.repeat(32)}`;
    const fullSource = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/ticket-workflow.js'), 'utf8');
    const boundary = fullSource.slice(0, fullSource.indexOf('        function disclosure('))
        + '\nwindow.fixture = {draft, nonce, persist};\n});\n})();';
    const handlers = {}, elements = [];
    const form = {querySelector: () => null, querySelectorAll: () => elements, prepend() {},
        append(el) { elements.push(el); }, addEventListener(k, fn) { handlers[k] = fn; }};
    const surface = {dataset: {workflow: JSON.stringify({ticket_id: 42, copy: {}, ...meta})}};
    const document = {readyState: 'complete', addEventListener() {}, createElement: () => ({setAttribute() {}}),
        getElementById: id => id === 'comment-form' ? form : {dataset: {scope: phpScope}},
        querySelector: selector => selector.startsWith('meta[') ? (scope ? {content: scope} : null) : surface};
    const window = {crypto: {randomUUID: () => 'TEST-new-nonce'}, addEventListener() {},
        commentEditor: {root: {innerHTML: '<p>Reply</p>'}, on() {}},
        internalEditor: {root: {innerHTML: '<p>Private internal note</p>'}, on() {}}};
    vm.runInNewContext(boundary, {window, document, localStorage: local, sessionStorage: session,
        setTimeout: () => 1, clearTimeout() {}, Date});
    return {...window.fixture, handlers};
}

test('workflow replies and internal notes are scoped to this login and tab', () => {
    const local = storage(), session = storage(), scope = `10:${'a'.repeat(32)}`;
    const a = workflow(scope, local, session);
    a.handlers.input(); a.persist();
    assert.equal(local.length, 0);
    assert.equal(session.length, 1);
    assert.equal(workflow(scope, local, session).draft.internal, '<p>Private internal note</p>');
    assert.equal(workflow(scope, local, storage()).draft, null);
    for (const other of [`11:${'a'.repeat(32)}`, `10:${'b'.repeat(32)}`, '', 'invalid']) {
        const before = session.length;
        const b = workflow(other, local, session);
        assert.equal(b.draft, null);
        b.handlers.input(); b.persist();
        if (!other || other === 'invalid') {
            assert.equal(local.length, 0);
            assert.equal(session.length, before, 'Unscoped workflow must not persist private content');
        }
    }
});

test('workflow rejects stale, future and invalid dates before acknowledgement or persistence', () => {
    const local = storage(), session = storage(), scope = `10:${'a'.repeat(32)}`;
    const a = workflow(scope, local, session); a.handlers.input(); a.persist();
    const key = session.key(0), saved = JSON.parse(session.getItem(key));
    for (const timestamp of [Date.now() - 25 * 3600000, Date.now() + 3600000, 'invalid', null]) {
        session.setItem(key, JSON.stringify({...saved, saved: timestamp}));
        assert.equal(workflow(scope, local, session, {draft_ack: saved.nonce}).draft, null);
        assert.equal(session.getItem(key), null);
    }
    a.handlers.compositionstart(); a.persist();
    assert.equal(session.length, 0);
    a.handlers.compositionend(); a.persist();
    assert.equal(session.length, 1);
    const acknowledged = workflow(scope, local, session, {draft_ack: saved.nonce});
    assert.equal(acknowledged.draft.content, '');
    assert.equal(acknowledged.draft.internal, '<p>Private internal note</p>');
});

function sidebarTimer(scope, session, data) {
    const full = fs.readFileSync(require('node:path').join(__dirname, '../includes/header.php'), 'utf8');
    const start = full.indexOf('(function() {', full.indexOf('<!-- Draft timer'));
    const source = full.slice(start, full.indexOf('</script>', start)).replace(/<\?php[\s\S]*?\?>/g, code =>
        code.includes('timer_alert_hours') ? '3' : code.includes('timer_alert_enabled') ? 'true' : '"Timer"');
    const nodes = {}, handlers = {}, toasts = [];
    function node() { return {children: [], style: {}, appendChild(el) { this.children.push(el); },
        insertBefore(el) { this.children.unshift(el); }, remove() { delete nodes[this.id];
            const list = nodes['sidebar-timers-list']; list.children = list.children.filter(el => el !== this); }}; }
    nodes['sidebar-timers'] = node(); nodes['sidebar-timers-list'] = node();
    const key = `foxdesk_draft_v2_${scope}:foxdesk_draft_timer`;
    if (data) session.setItem(key, JSON.stringify(data));
    const document = {querySelector: s => s.startsWith('meta[') ? {content: scope} : null,
        getElementById: id => nodes[id] || nodes['sidebar-timers-list'].children.find(el => el.id === id),
        createElement: node, addEventListener: (name, fn) => { handlers[name] = fn; }};
    vm.runInNewContext(source, {document, window: {location: {search: '?page=work'}},
        sessionStorage: session, localStorage: storage(), setInterval() {}, showAppToast: msg => toasts.push(msg)});
    handlers.DOMContentLoaded();
    return {row: nodes['sidebar-timers-list'].children[0], toasts, key};
}

test('sidebar reads the new-ticket timer, alerts once and discards scoped state', () => {
    const session = storage(), scope = `10:${'a'.repeat(32)}`;
    const data = {startedAt: Date.now() - 4 * 3600000, timestamp: Date.now(), state: 'running', pausedTotal: 0};
    const a = sidebarTimer(scope, session, data);
    assert.ok(a.row, 'Scoped running timer must be visible on another page');
    assert.equal(a.toasts.length, 1);
    assert.equal(sidebarTimer(scope, session).toasts.length, 0);
    a.row.children[1].onclick({stopPropagation() {}});
    assert.equal(session.length, 0);
    const paused = sidebarTimer(scope, session, {...data, state: 'paused', pausedAt: Date.now()});
    assert.ok(paused.row);
    assert.equal(sidebarTimer(`11:${'b'.repeat(32)}`, session).row, undefined);
    for (const timestamp of [Date.now() - 25 * 3600000, Date.now() + 100000, 'invalid']) {
        const invalid = sidebarTimer(scope, session, {...data, timestamp});
        assert.equal(invalid.row, undefined);
        assert.equal(session.getItem(invalid.key), null);
    }
});
test('draft expiration, submit clearing and IME protection remain effective', () => {
    const local = storage(), session = storage();
    const { draft } = load(`10:${'a'.repeat(32)}`, local, session);
    draft._compositionDepth = 1;
    draft.save();
    assert.equal(session.length, 0);
    draft._compositionDepth = 0;
    for (const timestamp of [Date.now() - 25 * 3600000, Date.now() + 3600000, 'invalid']) {
        session.setItem(draft.key, JSON.stringify({ timestamp, fields: { title: 'Expired' } }));
        assert.equal(draft.restore(), false);
        assert.equal(session.length, 0);
    }
    draft.save();
    draft.clear();
    assert.equal(session.length, 0);
});

test('real submit event clears the draft; periodic save waits for compositionend', () => {
    const session = storage();
    const a = load(`10:${'a'.repeat(32)}`, storage(), session);
    a.draft.formSelector = '#form';
    a.draft.init();
    a.field.handlers.compositionstart();
    a.draft.dirty = true;
    a.tick();
    assert.equal(session.length, 0);
    a.field.handlers.compositionend();
    a.tick();
    assert.equal(session.length, 1);
    a.form.handlers.submit();
    assert.equal(session.length, 0);
});

test('draft timer uses scoped session storage and rejects expired data', () => {
    const local = storage(), session = storage(), scope = `10:${'a'.repeat(32)}`;
    const php = fs.readFileSync(require('node:path').join(__dirname, '../includes/components/new-ticket-assets.php'), 'utf8').replace(/\r\n/g, '\n');
    const start = php.indexOf('(function() {\n    var STORAGE_KEY');
    const timerSource = php.slice(start, php.indexOf('})();', start) + 5).replace(/<\?php[\s\S]*?\?>/g, 'TEST');
    function timer(currentScope) {
        const autosave = load(currentScope, local, session).autosave;
        const node = () => ({dataset: {}, handlers: {}, classList: {add() {}, remove() {}}, addEventListener(k, fn) { this.handlers[k] = fn; }, appendChild() {}});
        const button = node(), icon = node(), text = node(), hidden = node(), wrapper = node(), discard = node();
        button.querySelector = s => s.includes('icon') ? icon : text;
        const nodes = {'new-ticket-timer': wrapper, 'nt-timer-btn': button, 'nt-timer-discard': discard, timer_elapsed_seconds: hidden};
        vm.runInNewContext(timerSource, {FoxDeskAutosave: autosave, sessionStorage: session, document: {getElementById: id => nodes[id], createElement: node}, setInterval: () => 1, clearInterval() {}, confirm: () => true});
        return {button, key: autosave.storageKey('foxdesk_draft_timer')};
    }
    local.setItem('foxdesk_draft_timer', 'legacy timer');
    const a = timer(scope);
    assert.equal(local.length, 0);
    a.button.handlers.click();
    assert.equal(JSON.parse(session.getItem(a.key)).state, 'running');
    assert.equal(timer(scope).button.dataset.state, 'running');
    const saved = JSON.parse(session.getItem(a.key));
    session.setItem(a.key, JSON.stringify({...saved, timestamp: Date.now() - 25 * 3600000}));
    assert.equal(timer(scope).button.dataset.state, undefined);
    assert.equal(session.getItem(a.key), null);
    a.button.handlers.click();
    assert.equal(timer(`11:${'b'.repeat(32)}`).button.dataset.state, undefined);
});

test('work search never persists personal queries across accounts or beyond 24 h', () => {
    const local = storage(), session = storage();
    const workSource = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/work-dashboard.js'), 'utf8').split('// Chart tooltip:')[0];
    function search(scope) {
        const input = {value: '', handlers: {}, addEventListener(k, fn) { this.handlers[k] = fn; }};
        const surface = {dataset: {}, getAttribute: () => 'search', hasAttribute: () => false,
            querySelector: s => s === '[data-work-log-search]' ? input : null, querySelectorAll: () => []};
        const document = {readyState: 'complete', addEventListener() {}, querySelector: () => ({content: scope}),
            querySelectorAll: s => s === '[data-work-log-surface]' ? [surface] : []};
        vm.runInNewContext(workSource, {document, localStorage: local, sessionStorage: session});
        return input;
    }
    local.setItem('foxdesk_work_my_search', 'TEST-personal query');
    const scope = `10:${'a'.repeat(32)}`, a = search(scope);
    assert.equal(local.length, 0);
    a.value = 'TEST-customer name';
    a.handlers.compositionstart();
    a.handlers.input({isComposing: true});
    assert.equal(session.length, 0);
    a.handlers.compositionend();
    assert.equal(search(scope).value, 'TEST-customer name');
    assert.equal(search(`11:${'a'.repeat(32)}`).value, '');
    const key = session.key(0), saved = JSON.parse(session.getItem(key));
    session.setItem(key, JSON.stringify({...saved, timestamp: Date.now() - 25 * 3600000}));
    assert.equal(search(scope).value, '');
});
