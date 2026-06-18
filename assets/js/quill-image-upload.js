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

    window.initQuillImageUpload = function (quill, options) {
        if (!quill || !quill.root) return;

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
                var imgUrl = 'image.php?f=' + encodeURIComponent(data.file.filename);
                quill.focus();
                // Re-check index — selection may have changed during async upload
                var currentRange = quill.getSelection(true);
                var insertAt = currentRange ? currentRange.index : index;
                quill.insertEmbed(insertAt, 'image', imgUrl);
                quill.setSelection(insertAt + 1);
            } else {
                var errMsg = data.error || 'Upload failed';
                console.error('Quill image upload:', errMsg, data);
                showToast(errMsg);
            }
        })
        .catch(function (err) {
            console.error('Quill image upload error:', err);
            showToast(err.message || 'Image upload failed');
        });
    }

    function showToast(msg) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(msg, 'error');
        }
    }
})();
