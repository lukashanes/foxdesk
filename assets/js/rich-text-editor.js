(function (window, document) {
    'use strict';

    function editorHtml(editor) {
        if (!editor) return '';

        if (typeof editor.getSemanticHTML === 'function') {
            try {
                var length = typeof editor.getLength === 'function' ? editor.getLength() : undefined;
                var html = typeof length === 'number'
                    ? editor.getSemanticHTML(0, length)
                    : editor.getSemanticHTML();
                if (typeof html === 'string') return html.trim();
            } catch (error) {
                // Fall back to Quill's DOM when semantic export is unavailable.
            }
        }

        return editor.root ? String(editor.root.innerHTML || '').trim() : '';
    }

    function isBlankHtml(html) {
        html = String(html || '').trim();
        if (html === '') return true;
        if (/<img\b/i.test(html)) return false;

        var normalized = html
            .replace(/<p><br><\/p>/gi, '')
            .replace(/<p>\s*<\/p>/gi, '')
            .replace(/<br\s*\/?>/gi, '')
            .replace(/&nbsp;/gi, ' ');

        var scratch = document.createElement('div');
        scratch.innerHTML = normalized;
        return (scratch.textContent || '').replace(/\u00a0/g, ' ').trim() === '';
    }

    function fieldValue(editor) {
        var html = editorHtml(editor);
        return isBlankHtml(html) ? '' : html;
    }

    function loadHtml(editor, content) {
        if (!editor) return;
        content = String(content || '');

        if (content.indexOf('<') !== -1 && content.indexOf('>') !== -1) {
            editor.clipboard.dangerouslyPasteHTML(content);
        } else {
            editor.setText(content);
        }
    }

    window.FoxDeskRichText = {
        editorHtml: editorHtml,
        isBlankHtml: isBlankHtml,
        fieldValue: fieldValue,
        loadHtml: loadHtml
    };
})(window, document);
