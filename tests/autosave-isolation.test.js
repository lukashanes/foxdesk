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
