import * as bootstrap from 'bootstrap';
import naja from 'naja';

naja.initialize();

window.JobRadar = Object.freeze({ bootstrap, naja });

document.querySelectorAll('form[data-search-request]').forEach(form => {
    const boxes = [...form.querySelectorAll('input[type="checkbox"][data-priority]')];
    const submit = form.querySelector('button[type="submit"], button[name="send"]');
    const update = () => {
        const selected = boxes.filter(box => box.checked).length;
        const groups = ['A', 'B', 'C'].map(p => `${p}: ${boxes.filter(box => box.dataset.priority === p).length}`).join(' · ');
        form.querySelector('[data-source-count]').textContent = `Vybráno ${selected} z ${boxes.length} · ${groups}`;
        if (submit) submit.disabled = selected === 0;
    };
    form.querySelector('[data-source-actions]').hidden = false;
    form.querySelectorAll('[data-source-select]').forEach(button => button.addEventListener('click', () => {
        const group = button.dataset.sourceSelect;
        boxes.forEach(box => {
            if (group === 'all') box.checked = true;
            else if (group === 'none') box.checked = false;
            else if (group === 'A') box.checked = box.dataset.priority === 'A';
            else if (box.dataset.priority === group) box.checked = true;
        });
        update();
    }));
    boxes.forEach(box => box.addEventListener('change', update));
    window.addEventListener('pageshow', update);
    update();
    form.addEventListener('submit', event => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        queueMicrotask(() => {
            if (!event.defaultPrevented) form.dataset.submitting = 'true';
        });
    });
    window.addEventListener('pageshow', () => { delete form.dataset.submitting; });
});

// Only progress is refreshed: decision forms retain unsaved values.
if (document.querySelector('[data-execution-poll]')) {
    let polling = false;
    setInterval(async () => {
        if (polling || document.hidden) return;
        polling = true;
        try {
            const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || response.redirected) return;
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = page.querySelector('#execution-status');
            const current = document.querySelector('#execution-status');
            if (replacement && current && !current.contains(document.activeElement)) current.replaceWith(replacement);
        } catch { /* Preserve last known state during connectivity loss. */ }
        finally { polling = false; }
    }, 15000);
}
