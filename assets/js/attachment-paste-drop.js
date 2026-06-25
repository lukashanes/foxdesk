(function () {
    'use strict';

    function showError(message) {
        if (!message) return;
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(message, 'error');
            return;
        }
        if (typeof window.showToastGlobal === 'function') {
            window.showToastGlobal(message, 'error');
        }
    }

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function fileSignature(file) {
        return [
            String(file && file.name || ''),
            String(file && file.size || 0),
            String(file && file.lastModified || 0),
            String(file && file.type || '')
        ].join('::');
    }

    function extensionFromType(type) {
        var map = {
            'image/png': '.png',
            'image/jpeg': '.jpg',
            'image/gif': '.gif',
            'image/webp': '.webp',
            'image/heic': '.heic',
            'image/heif': '.heif'
        };
        return map[String(type || '').toLowerCase()] || '.png';
    }

    function normalizeClipboardFile(file, prefix) {
        if (!file || file.name) return file;
        var name = (prefix || 'pasted-image') + '-' + Date.now() + extensionFromType(file.type);
        try {
            return new File([file], name, {
                type: file.type || 'application/octet-stream',
                lastModified: file.lastModified || Date.now()
            });
        } catch (error) {
            return file;
        }
    }

    function acceptTokens(input) {
        return String(input && input.getAttribute('accept') || '')
            .split(',')
            .map(function (token) { return token.trim().toLowerCase(); })
            .filter(Boolean);
    }

    function acceptsFile(input, file) {
        var tokens = acceptTokens(input);
        if (tokens.length === 0) return true;

        var name = String(file && file.name || '').toLowerCase();
        var type = String(file && file.type || '').toLowerCase();

        return tokens.some(function (token) {
            if (token.charAt(0) === '.') return name.endsWith(token);
            if (token.slice(-2) === '/*') return type.indexOf(token.slice(0, -1)) === 0;
            return type === token;
        });
    }

    function clipboardFiles(event, prefix) {
        var clipboard = event && event.clipboardData;
        if (!clipboard) return [];

        var files = [];
        if (clipboard.items && clipboard.items.length) {
            toArray(clipboard.items).forEach(function (item) {
                if (item.kind !== 'file') return;
                var file = item.getAsFile();
                if (file) files.push(normalizeClipboardFile(file, prefix));
            });
        }

        if (files.length === 0 && clipboard.files && clipboard.files.length) {
            files = toArray(clipboard.files).map(function (file) {
                return normalizeClipboardFile(file, prefix);
            });
        }

        return files;
    }

    function appendToInput(input, files, options) {
        options = options || {};
        if (!input || !files || files.length === 0 || typeof DataTransfer === 'undefined') {
            return { added: 0, rejected: 0 };
        }

        var dt = new DataTransfer();
        var seen = Object.create(null);
        var added = 0;
        var rejected = 0;

        toArray(input.files).forEach(function (file) {
            seen[fileSignature(file)] = true;
            dt.items.add(file);
        });

        toArray(files).forEach(function (file) {
            if (!file) return;
            var normalized = normalizeClipboardFile(file, options.namePrefix);
            var signature = fileSignature(normalized);
            if (seen[signature]) return;
            if (!acceptsFile(input, normalized)) {
                rejected += 1;
                return;
            }
            seen[signature] = true;
            dt.items.add(normalized);
            added += 1;
        });

        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        if (rejected > 0) {
            showError(options.invalidTypeMessage || (window.appConfig && window.appConfig.invalidFileTypeMsg) || 'Invalid file type.');
        }

        return { added: added, rejected: rejected };
    }

    function resolveTargets(config) {
        var selectors = [];
        if (config.targetSelector) selectors.push(config.targetSelector);
        if (Array.isArray(config.targetSelectors)) selectors = selectors.concat(config.targetSelectors);
        if (config.zoneId) selectors.push('#' + config.zoneId);

        var targets = [];
        selectors.forEach(function (selector) {
            toArray(document.querySelectorAll(selector)).forEach(function (target) {
                if (targets.indexOf(target) === -1) targets.push(target);
            });
        });
        return targets;
    }

    function bind(config) {
        config = config || {};
        var input = document.getElementById(config.inputId || '');
        if (!input) return null;

        var targets = resolveTargets(config);
        if (targets.length === 0) return null;

        function afterFilesChanged(result) {
            if (result.added > 0 && typeof config.onFilesChanged === 'function') {
                config.onFilesChanged(input.files);
            }
        }

        function handlePaste(event) {
            var files = clipboardFiles(event, config.namePrefix);
            if (files.length === 0) return;
            event.preventDefault();
            event.stopPropagation();
            if (event.stopImmediatePropagation) event.stopImmediatePropagation();
            afterFilesChanged(appendToInput(input, files, config));
        }

        function handleDragOver(event) {
            if (!event.dataTransfer || toArray(event.dataTransfer.types).indexOf('Files') === -1) return;
            event.preventDefault();
            event.currentTarget.classList.add('dragover');
        }

        function handleDragLeave(event) {
            event.currentTarget.classList.remove('dragover');
        }

        function handleDrop(event) {
            var files = event.dataTransfer && event.dataTransfer.files ? toArray(event.dataTransfer.files) : [];
            if (files.length === 0) return;
            event.preventDefault();
            event.stopPropagation();
            if (event.stopImmediatePropagation) event.stopImmediatePropagation();
            event.currentTarget.classList.remove('dragover');
            afterFilesChanged(appendToInput(input, files, config));
        }

        targets.forEach(function (target) {
            target.addEventListener('paste', handlePaste, true);
            target.addEventListener('dragover', handleDragOver, true);
            target.addEventListener('dragleave', handleDragLeave, true);
            target.addEventListener('drop', handleDrop, true);
        });

        return {
            appendFiles: function (files) {
                return appendToInput(input, files, config);
            },
            destroy: function () {
                targets.forEach(function (target) {
                    target.removeEventListener('paste', handlePaste, true);
                    target.removeEventListener('dragover', handleDragOver, true);
                    target.removeEventListener('dragleave', handleDragLeave, true);
                    target.removeEventListener('drop', handleDrop, true);
                });
            }
        };
    }

    window.FoxDeskAttachmentPasteDrop = {
        bind: bind,
        appendToInput: appendToInput,
        clipboardFiles: clipboardFiles
    };

    function autoBindKnownSurfaces() {
        if (document.getElementById('comment-file-input')) {
            bind({
                inputId: 'comment-file-input',
                targetSelectors: ['#comment-form', '#comment-upload-zone'],
                namePrefix: 'ticket-attachment'
            });
        }
        if (document.getElementById('file-input') && document.getElementById('new-ticket-form')) {
            bind({
                inputId: 'file-input',
                targetSelectors: ['#new-ticket-form', '#upload-zone'],
                namePrefix: 'ticket-attachment'
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoBindKnownSurfaces);
    } else {
        autoBindKnownSurfaces();
    }
})();
