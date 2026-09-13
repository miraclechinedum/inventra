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

// ── Add product form ────────────────────────────────────────────────────────────────────────────
// The CSP-safe Alpine build does not evaluate expressions written into attributes, so every piece
// of behaviour this form needs is declared here as a component and referenced by name only.

// Local preview of the chosen photograph. Purely a convenience: the file input is a real, labelled
// control that submits on its own, and the server re-reads and re-validates whatever arrives.
Alpine.data('productImageField', () => ({
    preview: null,
    name: '',
    init() {
        this.revoke = () => this.preview && URL.revokeObjectURL(this.preview);
        window.addEventListener('beforeunload', this.revoke);
    },
    destroy() {
        this.revoke();
        window.removeEventListener('beforeunload', this.revoke);
    },
    pick() { this.$refs.file.removeAttribute('capture'); this.$refs.file.click(); },
    // `capture` asks a mobile browser for the camera. A desktop browser that does not understand it
    // simply opens the ordinary file picker, which is the intended fallback.
    capture() { this.$refs.file.setAttribute('capture', 'environment'); this.$refs.file.click(); },
    changed() {
        const file = this.$refs.file.files?.[0];
        this.revoke();
        this.preview = file ? URL.createObjectURL(file) : null;
        this.name = file ? file.name : '';
    },
    clear() {
        this.revoke();
        this.$refs.file.value = '';
        this.$refs.file.removeAttribute('capture');
        this.preview = null;
        this.name = '';
    },
}));

// The − / + buttons around a numeric field. The input itself stays a normal number input that can
// be typed into and submits its own value; these only nudge it. Laravel remains the only authority
// on what is an acceptable quantity.
Alpine.data('stepper', () => ({
    step(direction) {
        const input = this.$refs.input;
        const size = Number(input.step) || 1;
        const current = Number(input.value);
        const next = (Number.isFinite(current) ? current : 0) + size * direction;
        const min = input.min === '' ? -Infinity : Number(input.min);
        input.value = String(Math.max(min, next));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    },
    down() { this.step(-1); },
    up() { this.step(1); },
}));

// Searchable category field with optional inline creation.
//
// The authoritative value is a hidden input carrying category_id, so the server still receives an
// id it validates against active categories; the visible text box is only how the user finds one.
// Implemented as a WAI-ARIA combobox: a text input with role=combobox owning a listbox, the active
// option tracked with aria-activedescendant, and real keyboard handling.
Alpine.data('categoryCombobox', () => ({
    options: [],
    canCreate: false,
    createUrl: '',
    open: false,
    query: '',
    active: -1,
    busy: false,
    error: '',
    selectedId: '',

    init() {
        // The CSP-safe Alpine build evaluates only identifiers and literals in attributes, so the
        // component reads its configuration from data attributes rather than constructor arguments.
        const root = this.$el.dataset;
        this.options = JSON.parse(root.categories || '[]');
        this.canCreate = root.canCreate === '1';
        this.createUrl = root.createUrl || '';
        this.selectedId = root.selectedId ? String(root.selectedId) : '';

        const current = this.options.find((option) => String(option.id) === this.selectedId);
        this.query = current ? current.name : '';
        // Keep the text box honest: if it is left holding something that is not the selection, it
        // is reset on blur rather than implying a choice the server never received.
        this.lastCommitted = this.query;
    },

    get normalized() {
        return this.query.trim().toLowerCase();
    },

    get matches() {
        if (this.normalized === '') return this.options;
        const starts = [], contains = [];
        for (const option of this.options) {
            const name = option.name.toLowerCase();
            if (name.startsWith(this.normalized)) starts.push(option);
            else if (name.includes(this.normalized)) contains.push(option);
        }
        return [...starts, ...contains];
    },

    // Offered only when the typed name matches nothing exactly and the user may create categories.
    get canOfferCreate() {
        return this.canCreate
            && this.normalized !== ''
            && !this.options.some((option) => option.name.toLowerCase() === this.normalized);
    },

    get rows() {
        return this.canOfferCreate ? [...this.matches, { create: true }] : this.matches;
    },

    get activeId() {
        return this.active >= 0 && this.active < this.rows.length ? 'category-option-' + this.active : null;
    },

    show() { this.open = true; this.error = ''; },

    input() {
        this.open = true;
        this.error = '';
        this.active = this.rows.length ? 0 : -1;
        // Typing invalidates any previous choice until something is committed again.
        this.selectedId = '';
    },

    move(step) {
        if (!this.open) { this.open = true; return; }
        const count = this.rows.length;
        if (!count) return;
        this.active = (this.active + step + count) % count;
    },

    choose(option) {
        this.selectedId = String(option.id);
        this.query = option.name;
        this.lastCommitted = option.name;
        this.open = false;
        this.active = -1;
    },

    enter(event) {
        if (!this.open) return;
        const row = this.rows[this.active];
        if (!row) return;
        event.preventDefault();
        if (row.create) this.create();
        else this.choose(row);
    },

    close() {
        this.open = false;
        this.active = -1;
    },

    blur() {
        // Give a click on an option time to land before tidying up.
        setTimeout(() => {
            if (this.busy) return;
            this.close();
            if (!this.selectedId) this.query = this.lastCommitted === undefined ? '' : this.lastCommitted;
        }, 150);
    },

    async create() {
        if (!this.canOfferCreate || this.busy) return;
        const name = this.query.trim();
        this.busy = true;
        this.error = '';
        try {
            const response = await fetch(this.createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ name }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                // The server decides; 403 and 422 both land here and are reported, not worked around.
                this.error = body?.errors?.name?.[0] || body?.message || 'The category could not be created.';
                return;
            }
            if (!this.options.some((option) => String(option.id) === String(body.id))) {
                this.options = [...this.options, { id: body.id, name: body.name }]
                    .sort((a, b) => a.name.localeCompare(b.name));
            }
            this.choose(body);
        } catch {
            this.error = 'The category could not be created. Check your connection and try again.';
        } finally {
            this.busy = false;
        }
    },
}));

// Product form: live duplicate warnings and pre-submit checks.
//
// Everything here is advisory. The server re-validates every field and owns the unique rules, so a
// warning that is dismissed, missed or tampered with changes nothing about what may be saved.
Alpine.data('productForm', () => ({
    duplicates: { sku: null, name: null },
    overridden: { name: false, cost: false },
    missing: [],
    summary: false,
    // Reactive mirrors of the price inputs. costExceedsSelling reads only these, never the DOM
    // directly, so Alpine's dependency tracker can see the fields it must react to; a getter that
    // reads form.elements[...].value instead is invisible to x-show and never re-evaluates on input.
    sellingPrice: '',
    costPrice: '',

    init() {
        this.checkUrl = this.$el.dataset.checkUrl;
        this.ignoreId = this.$el.dataset.productId || '';
        this.timers = {};
        const form = this.$el;
        this.sellingPrice = form.elements.selling_price ? form.elements.selling_price.value : '';
        this.costPrice = form.elements.cost_price ? form.elements.cost_price.value : '';
    },

    // The CSP-safe Alpine build only evaluates a single statement per x-on attribute, so an
    // "update this value AND clear that flag" input handler has to live here rather than as an
    // inline `a = x; b = y` expression, which throws a parser error and silently does nothing.
    watchSellingPrice(event) {
        this.sellingPrice = event.target.value;
        this.overridden.cost = false;
    },

    watchCostPrice(event) {
        this.costPrice = event.target.value;
        this.overridden.cost = false;
    },

    // Debounced so a lookup happens once the user pauses, not on every keystroke.
    watchField(field) {
        clearTimeout(this.timers[field]);
        this.duplicates[field] = null;
        if (field === 'name') this.overridden.name = false;
        const value = this.$el.value.trim();
        if (value === '') return;
        this.timers[field] = setTimeout(() => this.lookup(field, value), 350);
    },

    async lookup(field, value) {
        try {
            const url = new URL(this.checkUrl, window.location.origin);
            url.searchParams.set('field', field);
            url.searchParams.set('value', value);
            if (this.ignoreId) url.searchParams.set('ignore', this.ignoreId);
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;
            const body = await response.json();
            this.duplicates[field] = body.exists ? body : null;
        } catch {
            // A failed lookup simply shows no warning; the server still rejects a real duplicate.
        }
    },

    // The CSP-safe Alpine build cannot parse optional chaining in an attribute, so the template
    // reads these plain getters rather than reaching into the duplicate objects itself.
    get duplicateNameLabel() { return this.duplicates.name ? this.duplicates.name.name : ''; },
    get duplicateNameUrl() { return this.duplicates.name ? this.duplicates.name.url : '#'; },
    get duplicateSkuCode() { return this.duplicates.sku ? this.duplicates.sku.sku : ''; },
    get duplicateSkuLabel() { return this.duplicates.sku ? this.duplicates.sku.name : ''; },
    get duplicateSkuUrl() { return this.duplicates.sku ? this.duplicates.sku.url : '#'; },

    get costExceedsSelling() {
        const selling = this.toNumber(this.sellingPrice);
        const cost = this.toNumber(this.costPrice);
        return selling !== null && cost !== null && cost > selling;
    },

    toNumber(raw) {
        if (raw === undefined || raw === null || String(raw).trim() === '') return null;
        const value = Number(String(raw).replace(/,/g, ''));
        return Number.isFinite(value) ? value : null;
    },

    // "Go back" undoes nothing server-side; it just returns focus to the field so the user can
    // correct the price rather than dismiss the warning.
    goBack() {
        const form = document.getElementById('add-product-form');
        form?.elements?.cost_price?.focus();
    },

    // A SKU collision is the one warning that blocks: the server would refuse the save anyway.
    get blocking() {
        return this.duplicates.sku !== null;
    },

    submit(event) {
        const form = event.target;
        if (!form || !form.elements) return;
        this.missing = [];
        for (const field of ['name', 'sku', 'category_id', 'selling_price', 'cost_price', 'initial_stock', 'reorder_level']) {
            const input = form.elements[field];
            if (input && String(input.value).trim() === '') this.missing.push(field);
        }
        const unresolved = this.blocking
            || (this.duplicates.name !== null && !this.overridden.name)
            || (this.costExceedsSelling && !this.overridden.cost);

        if (this.missing.length || unresolved) {
            event.preventDefault();
            this.summary = this.missing.length > 0;
            const first = form.elements[this.missing[0]] ?? form.elements.name;
            first?.focus?.();
        }
    },
}));

// Edit Product modal on the inventory listing.
//
// Progressive enhancement over a real link: the pencil navigates to the full edit page on its own,
// and only when this script is running is the click intercepted and that page's form fragment shown
// in place. One form, one request class, one set of server rules behind both surfaces.
Alpine.data('editProductModal', () => ({
    open: false,
    loading: false,

    init() {
        this.onClick = (event) => {
            const trigger = event.target.closest('[data-edit-product]');
            if (!trigger || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            this.show(trigger.href, trigger);
        };
        document.addEventListener('click', this.onClick);
    },

    destroy() { document.removeEventListener('click', this.onClick); },

    async show(url, trigger) {
        this.opener = trigger;
        this.open = true;
        this.loading = true;
        this.$refs.body.innerHTML = '';
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error('unavailable');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fragment = doc.querySelector('[data-edit-product-fragment]');
            const form = doc.getElementById('edit-product-form');
            if (!fragment || !form) throw new Error('unavailable');

            const wrapper = document.createElement('form');
            wrapper.method = 'POST';
            wrapper.action = form.action;
            wrapper.className = 'ui-modal-form';
            wrapper.innerHTML = fragment.innerHTML;
            this.$refs.body.appendChild(wrapper);
            this.loading = false;
            this.$nextTick(() => this.$refs.panel.querySelector('input, select, textarea, button')?.focus());
        } catch {
            // Anything unexpected falls back to the page the link already pointed at.
            window.location.href = url;
        }
    },

    close() {
        if (!this.open) return;
        this.open = false;
        this.$refs.body.innerHTML = '';
        this.opener?.focus?.();
    },
}));

// Success messages clear themselves so a stale confirmation cannot be mistaken for a fresh one.
Alpine.data('autoDismiss', () => ({
    shown: true,
    init() {
        this.timer = setTimeout(() => { this.shown = false; }, 7000);
    },
    destroy() { clearTimeout(this.timer); },
    hide() { clearTimeout(this.timer); this.shown = false; },
}));

// "More details" and the inline new-category panel are plain disclosures.
Alpine.data('disclosure', (open = false) => ({
    open,
    toggle() { this.open = !this.open; },
}));

// Confirmation dialog for a consequential product-lifecycle action (archive, reactivate,
// permanent delete). Deliberately not the small popover used elsewhere for lighter-weight
// confirmations: this one is `position: fixed`, so it is never subject to an ancestor's
// `overflow: hidden`/`overflow-y: auto` clipping the way a popover positioned relative to its
// trigger would be inside the scrollable Edit Product modal body.
//
// Each trigger gets its own instance (`x-data="lifecycleConfirm"` on a wrapper around one button
// and one dialog), so there is never more than one dialog open per action and no cross-action
// state to keep in sync.
Alpine.data('lifecycleConfirm', () => ({
    open: false,
    submitting: false,

    openDialog() {
        this.opener = document.activeElement;
        this.open = true;
        this.$nextTick(() => this.$refs.confirmButton?.focus());
    },

    close() {
        if (!this.open || this.submitting) return;
        this.open = false;
        this.opener?.focus?.();
    },

    // Guards against a double click or a repeated Enter firing two submissions of a destructive
    // action; the button also disables itself so a second click physically cannot land.
    submit() {
        if (this.submitting) return false;
        this.submitting = true;
        return true;
    },

    trapFocus(event) {
        if (!this.open) return;
        const controls = [...this.$refs.panel.querySelectorAll('button, a[href]')]
            .filter((element) => element.getClientRects().length && !element.disabled);
        if (!controls.length) return;
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

// ── Public homepage ────────────────────────────────────────────────────────
// Plain listeners rather than Alpine: this page is the most likely to be served
// under an enforced CSP, and none of this needs expression evaluation. Every
// hook is a no-op on the authenticated pages, which carry no .inventra-home.
(() => {
    const home = document.querySelector('.inventra-home');
    if (!home) return;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // Opt in to motion only when the viewer has not asked us not to. The class
    // gates every transition, so with JS off or motion reduced the page renders
    // in its final state instead of staying invisible.
    if (!reduceMotion.matches) {
        document.documentElement.classList.add('js-motion');
    }

    // ── navbar: border/shadow once scrolled off the top
    const nav = document.querySelector('[data-home-nav]');
    if (nav) {
        const sync = () => nav.setAttribute('data-scrolled', String(window.scrollY > 8));
        sync();
        window.addEventListener('scroll', sync, { passive: true });
    }

    // ── mobile menu
    const toggle = document.querySelector('[data-home-menu-toggle]');
    if (nav && toggle) {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));

        const setOpen = (open) => {
            nav.setAttribute('data-menu', open ? 'open' : 'closed');
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            // Keeps keyboard focus out of a collapsed panel, the same way the
            // authenticated sidebar guards its own off-canvas menu.
            if (panel) panel.inert = !open;
        };
        setOpen(false);

        toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));

        // Following a link, pressing Escape, or growing past the mobile
        // breakpoint all close it — so focus never lands in a hidden panel.
        panel?.addEventListener('click', (event) => {
            if (event.target.closest('a')) setOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                setOpen(false);
                toggle.focus();
            }
        });
        window.matchMedia('(min-width: 1025px)').addEventListener('change', (event) => {
            if (event.matches) setOpen(false);
        });
    }

    // ── reveal on scroll
    const revealables = document.querySelectorAll('.home-reveal');
    if (reduceMotion.matches || !('IntersectionObserver' in window)) {
        revealables.forEach((el) => el.classList.add('is-visible'));
    } else {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

        revealables.forEach((el) => observer.observe(el));
    }
})();

// ── Icon-only button tooltips ───────────────────────────────────────────────────────────────────
//
// One delegated tooltip for every `[data-tooltip]` control in the app. The bubble is a single
// `position: fixed` element appended to <body>, so no card, table or modal overflow can clip it —
// a tooltip rendered inside the trigger's own box would be cut off by the inventory table's
// `overflow-x: auto` wrapper, which is exactly the failure this avoids.
//
// The tooltip is decoration only: every trigger carries its own `aria-label`, so assistive
// technology never depends on the bubble, and the bubble itself is aria-hidden. Touch devices get
// nothing on tap (no hover to rely on, and no bubble left stranded over the UI).
(() => {
    let bubble = null;
    let current = null;

    const ensureBubble = () => {
        if (bubble) return bubble;
        bubble = document.createElement('div');
        bubble.className = 'ui-tooltip';
        bubble.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bubble);
        return bubble;
    };

    const hide = () => {
        current = null;
        if (bubble) bubble.classList.remove('is-visible');
    };

    const show = (trigger) => {
        const text = trigger.getAttribute('data-tooltip');
        if (!text) return;

        current = trigger;
        const tip = ensureBubble();
        tip.textContent = text;
        tip.classList.add('is-visible');

        // Measure after the text is in place, then keep the bubble inside the viewport: above the
        // trigger by default, below it when there is no room above, and always clamped so it can
        // never push the page horizontally.
        const anchor = trigger.getBoundingClientRect();
        const size = tip.getBoundingClientRect();
        const gap = 8;
        const margin = 6;

        let top = anchor.top - size.height - gap;
        if (top < margin) top = anchor.bottom + gap;

        let left = anchor.left + (anchor.width - size.width) / 2;
        left = Math.max(margin, Math.min(left, window.innerWidth - size.width - margin));

        tip.style.top = `${Math.round(top)}px`;
        tip.style.left = `${Math.round(left)}px`;
    };

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') return;
        const trigger = event.target.closest('[data-tooltip]');
        if (trigger && trigger !== current) show(trigger);
    });

    document.addEventListener('pointerout', (event) => {
        const trigger = event.target.closest('[data-tooltip]');
        if (trigger && trigger === current) hide();
    });

    // Keyboard users get the same hint, but only from a real focus ring — not from a mouse click
    // that happens to leave focus behind on the button.
    document.addEventListener('focusin', (event) => {
        const trigger = event.target.closest('[data-tooltip]');
        if (trigger && trigger.matches(':focus-visible')) show(trigger);
    });

    document.addEventListener('focusout', hide);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide(); });
    // A tooltip anchored to a moving target would drift, so it is dismissed instead.
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
