(function () {
    'use strict';

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    function fillTemplate(template, replacements) {
        var output = String(template || '');
        Object.keys(replacements || {}).forEach(function (key) {
            output = output.split('{' + key + '}').join(replacements[key]);
        });
        return output;
    }

    function formatFileSize(bytes, decimals) {
        decimals = typeof decimals === 'number' ? decimals : 1;
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(decimals) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(decimals) + ' KB';
        return bytes + ' B';
    }

    function getFileIconName(mimeType) {
        mimeType = String(mimeType || '');
        if (mimeType.indexOf('image/') === 0) return 'file-image';
        if (mimeType === 'application/pdf') return 'file-pdf';
        if (mimeType.indexOf('word') !== -1) return 'file-word';
        if (mimeType.indexOf('excel') !== -1 || mimeType.indexOf('spreadsheet') !== -1) return 'file-excel';
        if (mimeType.indexOf('zip') !== -1 || mimeType.indexOf('rar') !== -1) return 'file-archive';
        return 'file';
    }

    function renderIcon(file, classes) {
        if (typeof window.getIcon === 'function') {
            return window.getIcon(getFileIconName(file.type), classes || '');
        }
        return '';
    }

    function showLimitMessage(message) {
        if (!message) return;
        if (window.showAppToast) {
            window.showAppToast(message, 'error');
        } else {
            alert(message);
        }
    }

    function renderErrors(container, messages) {
        if (!container) return;
        var uniqueMessages = Array.from(new Set((messages || []).filter(Boolean)));
        if (uniqueMessages.length === 0) {
            container.innerHTML = '';
            container.classList.add('hidden');
            return;
        }

        container.innerHTML = uniqueMessages.map(function (message) {
            return '<div>' + escapeHtml(message) + '</div>';
        }).join('');
        container.classList.remove('hidden');
    }

    function initUploadPreview(options) {
        var input = document.getElementById(options.inputId);
        var preview = document.getElementById(options.previewId);
        if (!input || !preview) return null;

        var errors = options.errorsId ? document.getElementById(options.errorsId) : null;
        var limit = options.limit || {};
        var removeLabel = options.removeLabel || 'Remove';
        var sizeDecimals = typeof options.sizeDecimals === 'number' ? options.sizeDecimals : 1;
        var rowClass = options.rowClass || 'flex items-center justify-between fd-rounded-control px-2 py-1.5 text-xs';
        var iconClass = options.iconClass || 'flex-shrink-0 w-3.5 h-3.5';
        var nameClass = options.nameClass || 'truncate';
        var sizeClass = options.sizeClass || 'flex-shrink-0';
        var removeIconClass = options.removeIconClass || 'w-3.5 h-3.5';
        var objectUrls = [];

        function isImageFile(file) {
            return String(file && file.type || '').indexOf('image/') === 0;
        }

        function revokeObjectUrls() {
            if (typeof URL === 'undefined' || typeof URL.revokeObjectURL !== 'function') {
                objectUrls = [];
                return;
            }
            objectUrls.forEach(function (url) {
                URL.revokeObjectURL(url);
            });
            objectUrls = [];
        }

        function createObjectUrl(file) {
            if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') return '';
            var url = URL.createObjectURL(file);
            objectUrls.push(url);
            return url;
        }

        function enforceLimits() {
            if (typeof DataTransfer === 'undefined') return { changed: false, hadErrors: false, messages: [] };

            var originalCount = input.files.length;
            var dt = new DataTransfer();
            var totalSize = 0;
            var hadErrors = false;
            var totalErrorShown = false;
            var messages = [];

            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];

                if (limit.single > 0 && file.size > limit.single) {
                    hadErrors = true;
                    var singleMessage = fillTemplate(limit.singleTemplate, {
                        name: file.name,
                        size: formatFileSize(limit.single, sizeDecimals)
                    });
                    messages.push(singleMessage);
                    showLimitMessage(singleMessage);
                    continue;
                }

                if (limit.total > 0 && totalSize + file.size > limit.total) {
                    hadErrors = true;
                    if (!totalErrorShown) {
                        var totalMessage = fillTemplate(limit.totalTemplate, {
                            size: formatFileSize(limit.total, sizeDecimals)
                        });
                        messages.push(totalMessage);
                        showLimitMessage(totalMessage);
                        totalErrorShown = true;
                    }
                    continue;
                }

                totalSize += file.size;
                dt.items.add(file);
            }

            if (originalCount !== dt.files.length) {
                input.files = dt.files;
                return { changed: true, hadErrors: hadErrors, messages: messages };
            }

            return { changed: false, hadErrors: hadErrors, messages: messages };
        }

        function removeFile(index) {
            var dt = new DataTransfer();
            for (var i = 0; i < input.files.length; i++) {
                if (i !== index) dt.items.add(input.files[i]);
            }
            input.files = dt.files;
            updatePreview();
        }

        function updatePreview() {
            var validation = enforceLimits();
            renderErrors(errors, validation.messages);
            revokeObjectUrls();
            preview.innerHTML = '';

            if (input.files.length === 0) {
                preview.classList.add('hidden');
                return validation;
            }

            preview.classList.remove('hidden');

            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                var isImage = isImageFile(file);
                var row = document.createElement('div');
                row.className = isImage ? 'upload-preview-card upload-preview-card--image' : rowClass;
                if (!isImage) row.style.background = 'var(--surface-secondary)';

                var meta = document.createElement('div');
                meta.className = isImage
                    ? 'upload-preview-card__meta'
                    : (options.metaClass || 'flex items-center gap-2 min-w-0');

                if (isImage) {
                    var thumbUrl = createObjectUrl(file);
                    var thumbButton = document.createElement('button');
                    thumbButton.type = 'button';
                    thumbButton.className = 'upload-preview-card__thumb';
                    thumbButton.setAttribute('aria-label', file.name);
                    if (thumbUrl) {
                        thumbButton.setAttribute('data-image-preview-trigger', '');
                        thumbButton.setAttribute('data-image-preview-src', thumbUrl);
                        thumbButton.setAttribute('data-image-preview-name', file.name);
                    }

                    var img = document.createElement('img');
                    img.src = thumbUrl;
                    img.alt = file.name;
                    img.loading = 'lazy';
                    thumbButton.appendChild(img);
                    row.appendChild(thumbButton);
                } else {
                    meta.innerHTML = renderIcon(file, iconClass);
                }

                var name = document.createElement('span');
                name.className = isImage ? 'upload-preview-card__name' : nameClass;
                if (!isImage) name.style.color = 'var(--text-secondary)';
                name.textContent = file.name;
                meta.appendChild(name);

                var size = document.createElement('span');
                size.className = isImage ? 'upload-preview-card__size' : sizeClass;
                if (!isImage) size.style.color = 'var(--text-muted)';
                size.textContent = formatFileSize(file.size, sizeDecimals);
                meta.appendChild(size);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = options.removeButtonClass || 'text-red-400 hover:text-red-500 ml-2';
                remove.title = removeLabel;
                remove.setAttribute('aria-label', removeLabel);
                remove.innerHTML = typeof window.getIcon === 'function' ? window.getIcon('times', removeIconClass) : '&times;';
                remove.addEventListener('click', removeFile.bind(null, i));

                row.appendChild(meta);
                row.appendChild(remove);
                preview.appendChild(row);
            }

            return validation;
        }

        if (window.initFileDropzone && options.zoneId) {
            window.initFileDropzone({
                zoneId: options.zoneId,
                inputId: options.inputId,
                onFilesChanged: updatePreview
            });
        } else {
            input.addEventListener('change', updatePreview);
        }

        return {
            updatePreview: updatePreview,
            enforceLimits: enforceLimits
        };
    }

    window.FoxDeskUploadPreview = {
        init: initUploadPreview,
        formatFileSize: formatFileSize,
        getFileIconName: getFileIconName
    };
})();
