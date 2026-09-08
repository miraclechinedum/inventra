import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.csp.esm';

window.Alpine = Alpine;

Alpine.data('navigation', () => ({
    menuOpen: false,
    compact: window.matchMedia('(max-width: 1100px)').matches,
    init() {
        this.media = window.matchMedia('(max-width: 1100px)');
        this.onResize = (event) => {
            this.compact = event.matches;
            this.menuOpen = false;
        };
        this.media.addEventListener('change', this.onResize);
        this.$nextTick(() => {
            if (!this.compact) document.querySelector('.inventra-nav [aria-current=page]')?.scrollIntoView({block: 'nearest'});
        });
    },
    destroy() { this.media.removeEventListener('change', this.onResize); },
    openMenu() {
        this.menuOpen = true;
        this.$nextTick(() => {
            document.querySelector('.inventra-nav [aria-current=page]')?.scrollIntoView({block: 'nearest'});
            document.querySelector('.inventra-menu-close').focus();
        });
    },
    closeMenu() {
        if (!this.menuOpen) return;
        this.menuOpen = false;
        this.$nextTick(() => document.getElementById('navigation-toggle').focus());
    },
    trapFocus(event) {
        if (!this.compact || !this.menuOpen) return;
        const controls = [...document.querySelectorAll('#app-navigation a, #app-navigation button')]
            .filter(element => element.getClientRects().length && !element.disabled);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
}));

Livewire.start();

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print-trigger], button[data-print-page]')) {
        window.print();
    }
});

// Shared record-list controls (currently the rows-per-page selector) submit their own GET form on
// change. Delegated here rather than as an inline handler or an Alpine expression: the CSP allows
// only nonce'd external scripts, and this bundle uses the CSP-safe Alpine build, which does not
// evaluate arbitrary expressions in attributes.
document.addEventListener('change', (event) => {
    const control = event.target.closest('[data-table-control]');
    if (control instanceof HTMLSelectElement && control.form) {
        control.form.requestSubmit();
    }
});

const productSearch = document.querySelector('[data-sale-product-search]');

if (productSearch) {
    const button = productSearch.querySelector('button');
    const status = document.querySelector('[data-sale-search-status]');
    button.disabled = false;

    productSearch.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        status.textContent = 'Searching products…';

        try {
            const url = new URL(productSearch.action);
            url.searchParams.set('product_search', productSearch.elements.product_search.value);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Search unavailable');
            const { products } = await response.json();
            if (!Array.isArray(products) || !products.every(product => Number.isInteger(product.id) && typeof product.label === 'string')) {
                throw new Error('Invalid search results');
            }

            document.querySelectorAll('[data-sale-product]').forEach(select => {
                const selected = select.value;
                const previous = select.selectedOptions[0]?.cloneNode(true);
                const options = [new Option('Select product', '')];
                products.forEach(product => options.push(new Option(product.label, String(product.id))));
                if (selected && previous && !products.some(product => String(product.id) === selected)) {
                    options.push(previous);
                }
                select.replaceChildren(...options);
                select.value = selected;
            });
            status.textContent = products.length
                ? `${products.length} products found. Your selected products and sale details have been kept.`
                : 'No matching products. Your selected products and sale details have been kept.';
        } catch {
            status.textContent = 'Product search is unavailable. Your sale details have been kept. Try again without reloading the page.';
        } finally {
            button.disabled = false;
        }
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    if (form.dataset.confirmMessage && !window.confirm(form.dataset.confirmMessage)) {
        event.preventDefault();
        return;
    }
    if (form.hasAttribute('data-submit-once') && !event.defaultPrevented) {
        form.dataset.submitting = 'true';
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
            button.disabled = true;
        });
    }
});
