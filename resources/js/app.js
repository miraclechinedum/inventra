import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.csp.esm';

window.Alpine = Alpine;

Livewire.start();

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print-trigger], button[data-print-page]')) {
        window.print();
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
