/**
 * FoxDesk Autosave Module
 * Saves form drafts to localStorage, restores on page load.
 * Supports Quill rich-text editors, inputs, selects, and hidden fields.
 *
 * Usage:
 *   var draft = FoxDeskAutosave.create({
 *       key: 'foxdesk_draft_new_ticket',
 *       formSelector: '#new-ticket-form',
 *       fields: [
 *           {name: 'title', selector: '#title', type: 'input'},
 *           {name: 'body', type: 'quill', editorKey: 'description'}
 *       ],
 *       quillEditors: {description: window.descriptionEditor},
 *       onRestore: function() { showAppToast('Draft restored', 'info'); }
 *   });
 *   draft.init();
 */
window.FoxDeskAutosave = (function() {
    'use strict';

    var SAVE_INTERVAL = 3000;
    var MAX_AGE_MS = 24 * 60 * 60 * 1000; // 24 hours

    function AutosaveInstance(config) {
        this.key = config.key;
        this.fields = config.fields || [];
        this.quillEditors = config.quillEditors || {};
        this.onRestore = config.onRestore || null;
        this.formSelector = config.formSelector || null;
        this.pillRestore = config.pillRestore || null; // callback(fieldName, value) for custom pill selectors
        this.dirty = false;
        this.submitted = false;
        this.intervalId = null;
        this._boundBeforeUnload = null;
    }

    AutosaveInstance.prototype.init = function() {
        var self = this;

        // Attach change listeners to all fields
        this.fields.forEach(function(field) {
            if (field.type === 'quill' && field.editorKey && self.quillEditors[field.editorKey]) {
                self.quillEditors[field.editorKey].on('text-change', function() {
                    self.dirty = true;
                });
            } else {
                var el = document.querySelector(field.selector);
                if (el) {
                    el.addEventListener('input', function() { self.dirty = true; });
                    el.addEventListener('change', function() { self.dirty = true; });
                }
            }
        });

        // Watch hidden inputs via MutationObserver (for pill selectors etc.)
        this.fields.forEach(function(field) {
            if (field.type === 'hidden' && field.selector) {
                var el = document.querySelector(field.selector);
                if (el) {
                    var obs = new MutationObserver(function() { self.dirty = true; });
                    obs.observe(el, {attributes: true, attributeFilter: ['value']});
                    // Also listen to direct value changes via setter
                    el.addEventListener('change', function() { self.dirty = true; });
                }
            }
        });

        // Periodic save
        this.intervalId = setInterval(function() {
            if (self.dirty && !self.submitted) {
                self.save();
                self.dirty = false;
            }
        }, SAVE_INTERVAL);

        // Clear on form submit
        if (this.formSelector) {
            var form = document.querySelector(this.formSelector);
            if (form) {
                form.addEventListener('submit', function() {
                    self.submitted = true;
                    self.clear();
                });
            }
        }

        // beforeunload warning
        this._boundBeforeUnload = function(e) {
            if (self.dirty && !self.submitted) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this._boundBeforeUnload);

        // Try restore
        this.restore();
    };

    AutosaveInstance.prototype.save = function() {
        var data = {timestamp: Date.now(), fields: {}};
        var self = this;

        this.fields.forEach(function(field) {
            data.fields[field.name] = self._getFieldValue(field);
        });

        // Only save if there's actual content
        var hasContent = false;
        for (var k in data.fields) {
            if (data.fields[k] && data.fields[k] !== '<p><br></p>' && data.fields[k] !== '<p></p>') {
                hasContent = true;
                break;
            }
        }

        if (hasContent) {
            try {
                localStorage.setItem(this.key, JSON.stringify(data));
            } catch(e) {
                // localStorage full or unavailable
            }
        }
    };

    AutosaveInstance.prototype.restore = function() {
        var raw;
        try {
            raw = localStorage.getItem(this.key);
        } catch(e) {
            return false;
        }
        if (!raw) return false;

        var data;
        try {
            data = JSON.parse(raw);
        } catch(e) {
            this.clear();
            return false;
        }

        if (!data || !data.fields || !data.timestamp) {
            this.clear();
            return false;
        }

        // Check staleness
        var age = Date.now() - data.timestamp;
        if (age > MAX_AGE_MS) {
            this.clear();
            return false;
        }

        // Check if draft has actual content
        var hasContent = false;
        for (var k in data.fields) {
            var v = data.fields[k];
            if (v && v !== '<p><br></p>' && v !== '<p></p>') {
                hasContent = true;
                break;
            }
        }
        if (!hasContent) {
            this.clear();
            return false;
        }

        // Restore fields
        var self = this;
        var restored = false;

        this.fields.forEach(function(field) {
            var value = data.fields[field.name];
            if (value !== undefined && value !== null) {
                self._setFieldValue(field, value);
                if (value && value !== '<p><br></p>') restored = true;
            }
        });

        if (restored && this.onRestore) {
            var relTime = _relativeTime(data.timestamp);
            this.onRestore(relTime);
        }

        return restored;
    };

    AutosaveInstance.prototype.clear = function() {
        try {
            localStorage.removeItem(this.key);
        } catch(e) {}
    };

    AutosaveInstance.prototype.destroy = function() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
        if (this._boundBeforeUnload) {
            window.removeEventListener('beforeunload', this._boundBeforeUnload);
        }
    };

    AutosaveInstance.prototype.suppressBeforeUnload = function() {
        this.submitted = true;
    };

    AutosaveInstance.prototype._getFieldValue = function(field) {
        if (field.type === 'quill' && field.editorKey && this.quillEditors[field.editorKey]) {
            return this.quillEditors[field.editorKey].root.innerHTML;
        }
        var el = document.querySelector(field.selector);
        return el ? el.value : '';
    };

    AutosaveInstance.prototype._setFieldValue = function(field, value) {
        if (field.type === 'quill' && field.editorKey && this.quillEditors[field.editorKey]) {
            var editor = this.quillEditors[field.editorKey];
            if (value && value !== '<p><br></p>' && value !== '<p></p>') {
                editor.clipboard.dangerouslyPasteHTML(value);
            }
            // Also sync to hidden input if selector provided
            if (field.selector) {
                var hidden = document.querySelector(field.selector);
                if (hidden) hidden.value = value || '';
            }
            return;
        }

        var el = document.querySelector(field.selector);
        if (!el) return;

        if (field.type === 'hidden' && this.pillRestore) {
            el.value = value;
            this.pillRestore(field.name, value);
        } else {
            el.value = value;
        }

        // Trigger change event so other listeners pick it up
        var evt = new Event('change', {bubbles: true});
        el.dispatchEvent(evt);
    };

    function _relativeTime(ts) {
        var diff = Date.now() - ts;
        var min = Math.floor(diff / 60000);
        if (min < 1) return 'just now';
        if (min < 60) return min + ' min ago';
        var hrs = Math.floor(min / 60);
        if (hrs < 24) return hrs + 'h ago';
        return Math.floor(hrs / 24) + 'd ago';
    }

    return {
        create: function(config) {
            return new AutosaveInstance(config);
        }
    };
})();
