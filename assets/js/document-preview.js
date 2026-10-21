// Draw documents as pixels/text only: never execute uploaded HTML or PDF actions.
const triggers = [...document.querySelectorAll('[data-document-preview]')];
const dialog = document.querySelector('[data-document-dialog]');
if (dialog && triggers.length) {
    const body = dialog.querySelector('[data-document-body]');
    const title = dialog.querySelector('[data-document-title]');
    const status = dialog.querySelector('[data-document-status]');
    const pager = dialog.querySelector('[data-document-pager]');
    const previous = dialog.querySelector('[data-document-prev]');
    const next = dialog.querySelector('[data-document-next]');
    let activeTask, returnFocus, abort, currentPage = 1, pdfDocument, renderTask, generation = 0;
    let renderer;
    const pdf = () => renderer ||= import('../vendor/pdfjs/6.3.289/pdf.min.mjs').then(module => {
        module.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/6.3.289/pdf.worker.min.mjs', import.meta.url).href;
        return module;
    });
    async function bytes(trigger, signal) {
        const url = new URL(trigger.href, location.href);
        if (url.origin !== location.origin || Number(trigger.dataset.size) > 20 * 1024 * 1024) throw Error('Preview unavailable');
        const response = await fetch(url, {credentials:'same-origin', signal});
        if (!response.ok || Number(response.headers.get('content-length')) > 20 * 1024 * 1024) throw Error('Preview unavailable');
        const mime = (response.headers.get('content-type') || '').split(';')[0];
        if (mime !== trigger.dataset.mime) throw Error('Unexpected file type');
        const blob = await response.blob();
        if (blob.size > 20 * 1024 * 1024) throw Error('Preview unavailable');
        return blob;
    }
    async function paint(document, pageNumber, canvas, width) {
        const page = await document.getPage(pageNumber);
        const base = page.getViewport({scale:1});
        const viewport = page.getViewport({scale:Math.min(width/base.width,1800/base.height)});
        canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
        return page.render({canvasContext:canvas.getContext('2d'), viewport});
    }
    async function showPage() {
        previous.disabled = next.disabled = true;
        status.textContent = dialog.dataset.loading;
        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', title.textContent);
        body.replaceChildren(canvas);
        try {
            renderTask = await paint(pdfDocument, currentPage, canvas, Math.min(1000, window.innerWidth-80));
            await renderTask.promise;
            pager.textContent = currentPage + ' / ' + pdfDocument.numPages;
            previous.disabled = currentPage <= 1; next.disabled = currentPage >= pdfDocument.numPages;
            status.textContent = '';
        } catch (_) { if(dialog.open) status.textContent = dialog.dataset.unavailable; }
    }
    async function open(trigger) {
        const run = ++generation;
        returnFocus = trigger; currentPage = 1; pdfDocument = null;
        title.textContent = trigger.dataset.name;
        dialog.querySelector('[data-document-download]').href = trigger.href;
        body.replaceChildren(); pager.textContent = ''; previous.disabled = next.disabled = true;
        dialog.querySelector('footer').hidden = trigger.dataset.mime !== 'application/pdf';
        status.textContent = dialog.dataset.loading; abort = new AbortController();
        dialog.showModal();
        try {
            const blob = await bytes(trigger, abort.signal);
            if (run !== generation) return;
            if (trigger.dataset.mime === 'application/pdf') {
                const module = await pdf();
                if (run !== generation) return;
                activeTask = module.getDocument({data:new Uint8Array(await blob.arrayBuffer()), isEvalSupported:false, useSystemFonts:true, isOffscreenCanvasSupported:false});
                pdfDocument = await activeTask.promise;
                if (run !== generation) return;
                await showPage();
            } else {
                const pre = document.createElement('pre'); pre.dir = 'auto';
                // Bound visible text; the original file remains downloadable.
                pre.textContent = (await blob.text()).slice(0, 200000);
                if (run !== generation) return;
                body.replaceChildren(pre); status.textContent = '';
            }
        } catch (_) { if(run === generation) status.textContent = dialog.dataset.unavailable; }
    }
    dialog.querySelector('[data-document-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        generation++; abort?.abort(); renderTask?.cancel(); activeTask?.destroy();
        pdfDocument = null; body.replaceChildren(); returnFocus?.focus();
    });
    previous.addEventListener('click', () => { if(currentPage > 1) {currentPage--; showPage();} });
    next.addEventListener('click', () => { if(pdfDocument && currentPage < pdfDocument.numPages) {currentPage++; showPage();} });
    triggers.forEach(trigger => trigger.addEventListener('click', event => { event.preventDefault(); open(trigger); }));
    // Lazy, two-at-a-time thumbnails; no unbounded document fetch on large threads.
    const queue = []; let running = 0;
    async function drain() {
        if (running >= 2 || !queue.length) return;
        const trigger = queue.shift(); running++;
        let task;
        try {
            const blob = await bytes(trigger);
            const thumb = trigger.querySelector('[data-document-thumb]');
            if (trigger.dataset.mime === 'application/pdf') {
                const module = await pdf();
                task = module.getDocument({data:new Uint8Array(await blob.arrayBuffer()),isEvalSupported:false,useSystemFonts:true,isOffscreenCanvasSupported:false});
                const document = await task.promise;
                const canvas = window.document.createElement('canvas'); canvas.setAttribute('aria-hidden','true');
                const render = await paint(document,1,canvas,180); await render.promise;
                thumb.replaceChildren(canvas);
            } else { const pre = window.document.createElement('pre'); pre.textContent = (await blob.text()).slice(0,700); pre.setAttribute('aria-hidden','true'); thumb.replaceChildren(pre); }
        } catch (_) { /* The file-type icon and download link remain usable. */ }
        finally { await task?.destroy(); running--; drain(); }
    }
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) { observer.unobserve(entry.target); queue.push(entry.target); drain(); }
    }), {rootMargin:'100px'});
    triggers.forEach(trigger => observer.observe(trigger));
}
