/**
 * ChipSelect — reusable autocomplete multi-select
 *
 * Usage:
 *   new ChipSelect({
 *     wrapId:      'cs-wrap',         // wrapper div id
 *     chipsId:     'cs-chips',        // chips container id
 *     inputId:     'cs-input',        // text input id
 *     dropdownId:  'cs-dropdown',     // dropdown container id
 *     hiddenId:    'cs-hidden',       // hidden inputs container id
 *     items:       [{id, name}, ...], // available options
 *     selected:    [id, ...],         // pre-selected ids
 *     name:        'field[]',         // hidden input name
 *     allowCreate: false,             // allow free-text entries (for tags)
 *     noMatchText: 'No matches'       // text when nothing found
 *   });
 */
(function (root) {
    'use strict';

    function _escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function ChipSelect(cfg) {
        var self       = this;
        this.items     = cfg.items || [];
        this.name      = cfg.name;
        this.selected  = [];
        this.activeIdx = -1;
        this.allowCreate  = cfg.allowCreate || false;
        this.noMatchText  = cfg.noMatchText || 'No matches';

        this.wrap     = document.getElementById(cfg.wrapId);
        this.chips    = document.getElementById(cfg.chipsId);
        this.input    = document.getElementById(cfg.inputId);
        this.dropdown = document.getElementById(cfg.dropdownId);
        this.hidden   = document.getElementById(cfg.hiddenId);

        if (!this.input) return;

        // Pre-select items
        (cfg.selected || []).forEach(function (id) {
            var item = self.items.find(function (it) { return it.id === id; });
            if (item) {
                self._addChip(item, true);
            } else if (self.allowCreate && typeof id === 'string' && id !== '') {
                // Pre-select a free-text tag that may not be in the items list
                self._addChip({ id: id, name: id }, true);
            }
        });

        // Focus input when clicking the wrap area
        this.wrap.addEventListener('click', function () { self.input.focus(); });

        // Filter dropdown on input
        this.input.addEventListener('input', function () { self._render(); });

        // Show dropdown on focus
        this.input.addEventListener('focus', function () { self._render(); });

        // Keyboard navigation
        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && self.input.value === '' && self.selected.length) {
                self._removeById(self.selected[self.selected.length - 1]);
                return;
            }
            if (e.key === 'Escape') {
                self.dropdown.classList.add('hidden');
                self.activeIdx = -1;
                return;
            }

            var opts = self.dropdown.querySelectorAll('.chip-select__option');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (opts.length) {
                    self.activeIdx = Math.min(self.activeIdx + 1, opts.length - 1);
                    self._highlightOption(opts);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (opts.length) {
                    self.activeIdx = Math.max(self.activeIdx - 1, 0);
                    self._highlightOption(opts);
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (self.activeIdx >= 0 && opts[self.activeIdx]) {
                    opts[self.activeIdx].click();
                } else if (self.allowCreate && self.input.value.trim() !== '') {
                    self._createFromInput();
                }
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('mousedown', function (e) {
            if (!self.wrap.contains(e.target) && !self.dropdown.contains(e.target)) {
                self.dropdown.classList.add('hidden');
                self.activeIdx = -1;
            }
        });
    }

    /* ── Render dropdown ── */
    ChipSelect.prototype._render = function () {
        var self  = this;
        var query = this.input.value.trim().toLowerCase();
        this.dropdown.innerHTML = '';
        this.activeIdx = -1;

        var visible = this.items.filter(function (it) {
            if (self.selected.indexOf(it.id) !== -1) return false;
            if (query && it.name.toLowerCase().indexOf(query) === -1) return false;
            return true;
        });

        if (visible.length === 0 && !(this.allowCreate && query)) {
            this.dropdown.innerHTML = '<div class="chip-select__empty">' + _escHtml(this.noMatchText) + '</div>';
        } else {
            visible.forEach(function (it) {
                var div = document.createElement('div');
                div.className = 'chip-select__option';
                div.textContent = it.name;
                div.addEventListener('click', function () {
                    self._addChip(it);
                    self.input.value = '';
                    self.input.focus();
                });
                self.dropdown.appendChild(div);
            });

            // "Create" option for free-text entries
            if (this.allowCreate && query) {
                var exactMatch = this.items.some(function (it) {
                    return it.name.toLowerCase() === query;
                });
                var alreadySelected = this.selected.some(function (id) {
                    return String(id).toLowerCase() === query;
                });
                if (!exactMatch && !alreadySelected) {
                    var createDiv = document.createElement('div');
                    createDiv.className = 'chip-select__option chip-select__option--create';
                    createDiv.innerHTML = '+ ' + _escHtml(this.input.value.trim());
                    createDiv.addEventListener('click', function () {
                        self._createFromInput();
                    });
                    this.dropdown.appendChild(createDiv);
                }
            }
        }
        this.dropdown.classList.remove('hidden');
    };

    /* ── Create a chip from the current input value ── */
    ChipSelect.prototype._createFromInput = function () {
        var val = this.input.value.trim();
        if (!val) return;
        // Strip leading # for tags
        val = val.replace(/^#/, '').trim();
        if (!val) return;

        var alreadySelected = this.selected.some(function (id) {
            return String(id).toLowerCase() === val.toLowerCase();
        });
        if (alreadySelected) {
            this.input.value = '';
            this._render();
            return;
        }

        // Check if it matches an existing item
        var existing = this.items.find(function (it) {
            return it.name.toLowerCase() === val.toLowerCase();
        });
        var item = existing || { id: val, name: val };

        this._addChip(item);
        this.input.value = '';
        this.input.focus();
    };

    /* ── Highlight keyboard-selected option ── */
    ChipSelect.prototype._highlightOption = function (opts) {
        var idx = this.activeIdx;
        opts.forEach(function (o, i) {
            o.classList.toggle('chip-select__option--active', i === idx);
        });
        if (opts[idx]) opts[idx].scrollIntoView({ block: 'nearest' });
    };

    /* ── Add a chip ── */
    ChipSelect.prototype._addChip = function (item, silent) {
        if (this.selected.indexOf(item.id) !== -1) return;
        this.selected.push(item.id);

        var self = this;
        var chip = document.createElement('span');
        chip.className = 'chip-select__chip';
        chip.dataset.id = item.id;
        chip.innerHTML = _escHtml(item.name) + ' <span class="chip-select__chip-x">&times;</span>';
        chip.querySelector('.chip-select__chip-x').addEventListener('click', function (e) {
            e.stopPropagation();
            self._removeById(item.id);
        });
        this.chips.appendChild(chip);

        // Hidden input for form submission
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = this.name;
        inp.value = item.id;
        inp.id = 'cs-h-' + this.name.replace(/[\[\]]/g, '') + '-' + item.id;
        this.hidden.appendChild(inp);

        // Track name for getSelectedNames
        if (!this._nameMap) this._nameMap = {};
        this._nameMap[item.id] = item.name;

        if (!silent) this._render();
    };

    /* ── Remove a chip by id ── */
    ChipSelect.prototype._removeById = function (id) {
        this.selected = this.selected.filter(function (s) { return s !== id; });
        var chip = this.chips.querySelector('[data-id="' + CSS.escape(String(id)) + '"]');
        if (chip) chip.remove();
        var inp = document.getElementById('cs-h-' + this.name.replace(/[\[\]]/g, '') + '-' + id);
        if (inp) inp.remove();
        this._render();
    };

    /* ── Get display names of selected items ── */
    ChipSelect.prototype.getSelectedNames = function () {
        var self = this;
        return this.selected.map(function (id) {
            if (self._nameMap && self._nameMap[id]) return self._nameMap[id];
            var item = self.items.find(function (it) { return it.id === id; });
            return item ? item.name : String(id);
        });
    };

    /* ── Get raw values of selected items ── */
    ChipSelect.prototype.getSelectedValues = function () {
        return this.selected.slice();
    };

    // Export
    root.ChipSelect = ChipSelect;
    root._escHtml = _escHtml;

})(window);
