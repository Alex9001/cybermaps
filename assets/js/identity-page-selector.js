(function () {
    'use strict';
    const config = window.cybermapsIdentityPages;
    if (!config) return;
    const states = new WeakMap();
    function cancelSearch(wrapper) {
        const previous = states.get(wrapper);
        states.delete(wrapper);
        if (previous && previous.controller) previous.controller.abort();
        wrapper.removeAttribute('aria-busy');
        wrapper.querySelector('.cybermaps-page-next').hidden = true;
        return previous;
    }
    async function search(wrapper, next) {
        const input = wrapper.querySelector('.cybermaps-page-query');
        const feedback = wrapper.querySelector('.cybermaps-page-feedback');
        const select = wrapper.querySelector('.catalog-parent-select');
        const more = wrapper.querySelector('.cybermaps-page-next');
        const query = input.value.trim();
        const previous = cancelSearch(wrapper);
        if (query.length < 2) { feedback.textContent = config.query; return; }
        const cursor = next && previous && previous.query === query ? previous.cursor : 0;
        const state = { query, cursor, controller: new AbortController() };
        states.set(wrapper, state);
        wrapper.setAttribute('aria-busy', 'true');
        more.hidden = true;
        try {
            const response = await fetch(config.url, {
                method: 'POST', credentials: 'same-origin', signal: state.controller.signal,
                body: new URLSearchParams({ action: 'cybermaps_identity_pages', nonce: config.nonce, query, cursor: String(cursor) })
            });
            const result = await response.json();
            if (!response.ok || !result.success || !Array.isArray(result.data.pages)) throw new Error(config.error);
            if (states.get(wrapper) !== state || input.value.trim() !== query) return;
            const current = select.selectedOptions[0];
            const saved = current && current.value !== '0' ? current.cloneNode(true) : null;
            while (select.options.length > 1) select.remove(1);
            if (saved) select.appendChild(saved);
            result.data.pages.forEach(function (page) {
                if (saved && saved.value === String(page.id)) return;
                const option = document.createElement('option');
                option.value = String(page.id);
                option.textContent = String(page.title);
                select.appendChild(option);
            });
            if (saved) select.value = saved.value;
            state.cursor = result.data.next_cursor;
            more.hidden = !result.data.has_more;
            feedback.textContent = result.data.pages.length ? config.found : config.empty;
        } catch (error) {
            if (error.name !== 'AbortError' && states.get(wrapper) === state && input.value.trim() === query) feedback.textContent = config.error;
        } finally {
            if (states.get(wrapper) === state) wrapper.removeAttribute('aria-busy');
        }
    }
    document.addEventListener('input', function (event) {
        if (event.target.matches('.cybermaps-page-query') && event.target.value.trim().length < 2) {
            const wrapper = event.target.closest('.cybermaps-page-selector');
            cancelSearch(wrapper);
            wrapper.querySelector('.cybermaps-page-feedback').textContent = config.query;
        }
    });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.cybermaps-page-search, .cybermaps-page-next');
        if (button) search(button.closest('.cybermaps-page-selector'), button.classList.contains('cybermaps-page-next'));
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches('.cybermaps-page-query')) {
            event.preventDefault();
            search(event.target.closest('.cybermaps-page-selector'), false);
        }
    });
}());
