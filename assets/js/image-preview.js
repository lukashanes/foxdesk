(function () {
    'use strict';

    var escListenerAttached = false;

    // Legacy imports can reference a missing file. Keep a readable download
    // link instead of a broken-image glyph and an empty lightbox.
    document.querySelectorAll('[data-image-preview-trigger] img').forEach(function (img) {
        function unavailable() {
            var link = img.closest('[data-image-preview-trigger]');
            if (!link) return;
            var message = document.createElement('span');
            message.className = 'attachment-unavailable';
            message.textContent = img.alt + ' · ' + (link.dataset.imageUnavailable || '');
            link.replaceChildren(message);
            link.removeAttribute('data-image-preview-trigger');
        }
        img.addEventListener('error', unavailable, { once: true });
        if (img.complete && img.naturalWidth === 0) unavailable();
    });

    function getLightbox() {
        return {
            root: document.getElementById('image-lightbox'),
            image: document.getElementById('lightbox-img'),
            name: document.getElementById('lightbox-name')
        };
    }

    function imageNameFromSource(src) {
        if (!src) return '';
        try {
            var url = new URL(src, window.location.origin);
            var fileParam = url.searchParams.get('f');
            if (fileParam) return decodeURIComponent(fileParam.split('/').pop() || fileParam);
            return decodeURIComponent(url.pathname.split('/').pop() || '');
        } catch (_) {
            var fallback = String(src).split('/').pop() || '';
            return decodeURIComponent((fallback.split('?')[0] || fallback));
        }
    }

    function nameFromImage(img) {
        return (img.getAttribute('alt') || img.getAttribute('title') || imageNameFromSource(img.currentSrc || img.src)).trim();
    }

    function openImagePreview(src, name) {
        var lightbox = getLightbox();
        if (!lightbox.root || !lightbox.image) return;

        if (src) {
            lightbox.image.src = src;
        } else {
            lightbox.image.removeAttribute('src');
        }
        lightbox.image.alt = name || '';
        if (lightbox.name) lightbox.name.textContent = name || '';
        lightbox.root.hidden = false;
        lightbox.root.setAttribute('aria-hidden', 'false');
        lightbox.root.classList.add('is-open');

        if (!escListenerAttached) {
            document.addEventListener('keydown', onKeydown);
            escListenerAttached = true;
        }
    }

    function closeImagePreview() {
        var lightbox = getLightbox();
        if (!lightbox.root || !lightbox.image) return;

        lightbox.root.classList.remove('is-open');
        lightbox.root.hidden = true;
        lightbox.root.setAttribute('aria-hidden', 'true');
        lightbox.image.removeAttribute('src');
        lightbox.image.alt = '';
        if (lightbox.name) lightbox.name.textContent = '';

        if (escListenerAttached) {
            document.removeEventListener('keydown', onKeydown);
            escListenerAttached = false;
        }
    }

    function onKeydown(event) {
        if (event.key === 'Escape') closeImagePreview();
    }

    function findPreviewTarget(target) {
        if (!target || !target.closest) return null;
        return target.closest('[data-image-preview-trigger], .ql-editor img, .rich-content img.rich-inline-image');
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented) return;

        var closeButton = event.target.closest && event.target.closest('[data-image-preview-close]');
        if (closeButton) {
            event.preventDefault();
            closeImagePreview();
            return;
        }

        var lightbox = document.getElementById('image-lightbox');
        if (lightbox && event.target === lightbox) {
            event.preventDefault();
            closeImagePreview();
            return;
        }

        var target = findPreviewTarget(event.target);
        if (!target) return;
        if (target.closest('.link-preview-card')) return;

        var src = target.getAttribute('data-image-preview-src');
        var name = target.getAttribute('data-image-preview-name') || '';
        if (!src && target.tagName === 'IMG') {
            src = target.currentSrc || target.src || '';
            name = name || nameFromImage(target);
        }
        if (!src) return;

        event.preventDefault();
        openImagePreview(src, name || imageNameFromSource(src));
    }, true);

    window.openImagePreview = openImagePreview;
    window.closeImagePreview = closeImagePreview;
})();
