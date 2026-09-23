/**
 * Quill Image Upload Handler
 *
 * Intercepts image paste and drag-drop events in Quill editors.
 * Uploads images to the server and inserts the URL instead of base64.
 * Prevents app crashes caused by huge base64 strings in form data.
 *
 * Usage: initQuillImageUpload(quillInstance, { uploadUrl, csrfToken, ticketId? })
 */
(function () {
    'use strict';

    var blobImageSourcesAllowed = false;

    window.initQuillImageUpload = function (quill, options) {
        if (!quill || !quill.root) return;
        allowBlobImageSources();

        var uploadUrl = options.uploadUrl || '';
        var csrfToken = options.csrfToken || '';
        var ticketId = options.ticketId || 0;

        // ── Toolbar image button → file picker instead of URL prompt ─────
        var toolbar = quill.getModule('toolbar');
        if (toolbar) {
            toolbar.addHandler('image', function () {
                openFilePicker(quill, uploadUrl, csrfToken, ticketId);
            });
        }

        // ── Paste handler ────────────────────────────────────────────────
        // Use capture phase to intercept before Quill's own paste handler
        quill.root.addEventListener('paste', function (e) {
            var clipboard = e.clipboardData;
            if (!clipboard || !clipboard.items) return;

            for (var i = 0; i < clipboard.items.length; i++) {
                var item = clipboard.items[i];
                if (item.type.indexOf('image/') === 0) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    var file = item.getAsFile();
                    if (file) {
                        uploadAndInsert(quill, file, uploadUrl, csrfToken, ticketId);
                    }
                    return;
                }
            }
        }, true);

        // ── Drop handler ─────────────────────────────────────────────────
        // Use capture phase to intercept before Quill processes the drop
        quill.root.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            if (!dt || !dt.files || dt.files.length === 0) return;

            var imageFiles = [];
            for (var i = 0; i < dt.files.length; i++) {
                if (dt.files[i].type.indexOf('image/') === 0) {
                    imageFiles.push(dt.files[i]);
                }
            }
            if (imageFiles.length === 0) return;

            // Must prevent default AND stop propagation before Quill handles it
            e.preventDefault();
            e.stopImmediatePropagation();

            for (var j = 0; j < imageFiles.length; j++) {
                uploadAndInsert(quill, imageFiles[j], uploadUrl, csrfToken, ticketId);
            }
        }, true);

        // Also intercept dragover to allow drop (required by browsers)
        quill.root.addEventListener('dragover', function (e) {
            if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1) {
                e.preventDefault();
            }
        }, true);

        // ── Strip any base64 images that Quill inserts despite our handlers ──
        // This is a safety net: observe editor mutations and replace data: images
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.tagName === 'IMG' && node.src && node.src.indexOf('data:') === 0) {
                        // Quill managed to insert a base64 image — remove it
                        node.remove();
                    }
                });
            });
        });
        observer.observe(quill.root, { childList: true, subtree: true });
    };

    /**
     * Open native file picker for image selection.
     */
    function openFilePicker(quill, uploadUrl, csrfToken, ticketId) {
        var input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/png, image/jpeg, image/gif, image/webp');
        input.click();
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                uploadAndInsert(quill, input.files[0], uploadUrl, csrfToken, ticketId);
            }
        });
    }

    /**
     * Upload an image file and insert it into the editor.
     */
    function uploadAndInsert(quill, file, uploadUrl, csrfToken, ticketId) {
        var range = quill.getSelection(true);
        var index = range ? range.index : quill.getLength();
        var previewUrl = createPreviewUrl(file);
        var previewIndex = null;

        if (previewUrl) {
            quill.focus();
            quill.insertEmbed(index, 'image', previewUrl, 'user');
            previewIndex = index;
            quill.setSelection(index + 1);
            decoratePreviewImage(quill, previewUrl, file.name || 'Pasted image');
        }

        // Build form data
        var formData = new FormData();
        formData.append('file', file, file.name || ('paste-' + Date.now() + '.png'));
        formData.append('csrf_token', csrfToken);
        if (ticketId) {
            formData.append('ticket_id', ticketId);
        } else {
            formData.append('purpose', 'editor-image');
        }

        fetch(uploadUrl, {
            method: 'POST',
            body: formData
        })
        .then(function (res) {
            if (!res.ok) {
                return res.text().then(function (t) {
                    throw new Error('HTTP ' + res.status + ': ' + t.substring(0, 200));
                });
            }
            return res.json();
        })
        .then(function (data) {
            if (data.success && data.file) {
                var imgUrl = data.file.url || ('image.php?f=' + encodeURIComponent(data.file.filename));
                if (previewUrl && replacePreviewImage(quill, previewUrl, imgUrl, data.file.original_name || file.name || 'Image')) {
                    revokePreviewUrl(previewUrl);
                    return;
                }

                removePreviewImage(quill, previewUrl, previewIndex);
                quill.focus();
                // Re-check index — selection may have changed during async upload
                var currentRange = quill.getSelection(true);
                var insertAt = currentRange ? currentRange.index : index;
                quill.insertEmbed(insertAt, 'image', imgUrl, 'user');
                quill.setSelection(insertAt + 1);
            } else {
                var errMsg = data.error || 'Upload failed';
                console.error('Quill image upload:', errMsg, data);
                removePreviewImage(quill, previewUrl, previewIndex);
                revokePreviewUrl(previewUrl);
                showToast(errMsg);
            }
        })
        .catch(function (err) {
            console.error('Quill image upload error:', err);
            removePreviewImage(quill, previewUrl, previewIndex);
            revokePreviewUrl(previewUrl);
            showToast(err.message || 'Image upload failed');
        });
    }

    function allowBlobImageSources() {
        if (blobImageSourcesAllowed || typeof window.Quill === 'undefined') return;
        var imageFormat = null;
        try {
            imageFormat = window.Quill.import && window.Quill.import('formats/image');
        } catch (error) {
            return;
        }
        if (!imageFormat || typeof imageFormat.sanitize !== 'function') return;

        var originalSanitize = imageFormat.sanitize.bind(imageFormat);
        imageFormat.sanitize = function (url) {
            if (String(url || '').indexOf('blob:') === 0) return url;
            return originalSanitize(url);
        };
        blobImageSourcesAllowed = true;
    }

    function createPreviewUrl(file) {
        if (!file || String(file.type || '').indexOf('image/') !== 0) return '';
        if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') return '';
        return URL.createObjectURL(file);
    }

    function revokePreviewUrl(url) {
        if (!url || typeof URL === 'undefined' || typeof URL.revokeObjectURL !== 'function') return;
        URL.revokeObjectURL(url);
    }

    function findImageBySrc(quill, src) {
        if (!quill || !quill.root || !src) return null;
        var images = quill.root.querySelectorAll('img');
        for (var i = 0; i < images.length; i++) {
            if (images[i].getAttribute('src') === src || images[i].src === src) return images[i];
        }
        return null;
    }

    function decoratePreviewImage(quill, src, name) {
        var image = findImageBySrc(quill, src);
        if (!image) return;
        image.classList.add('rich-inline-image', 'rich-inline-image--uploading');
        image.alt = name || 'Image';
        image.title = name || 'Image';
        image.setAttribute('data-image-preview-name', name || 'Image');
    }

    function replacePreviewImage(quill, previewUrl, finalUrl, name) {
        var image = findImageBySrc(quill, previewUrl);
        if (!image) return false;
        image.classList.remove('rich-inline-image--uploading');
        image.classList.add('rich-inline-image');
        image.src = finalUrl;
        image.alt = name || 'Image';
        image.title = name || 'Image';
        image.setAttribute('data-image-preview-name', name || 'Image');
        return true;
    }

    function removePreviewImage(quill, previewUrl, previewIndex) {
        if (!previewUrl) return;
        var image = findImageBySrc(quill, previewUrl);
        if (image) {
            image.remove();
            return;
        }
        if (quill && quill.root) {
            var brokenPreview = quill.root.querySelector('img[src="//:0"]');
            if (brokenPreview) {
                brokenPreview.remove();
                return;
            }
        }
        if (typeof previewIndex === 'number' && quill && typeof quill.deleteText === 'function') {
            quill.deleteText(previewIndex, 1, 'silent');
        }
    }

    function showToast(msg) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(msg, 'error');
        }
    }
})();
