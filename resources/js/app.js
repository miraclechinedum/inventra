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

// ── Record Sale ─────────────────────────────────────────────────────────────────────────────────
// One component for the whole Record Sale workflow: customer or walk-in, the cart, payment, the
// pre-sale discount round trip, and the success state. It is a single component because these are
// states of one transaction rather than independent widgets — the cart and the buyer decide whether
// an approved discount still applies, so they must be able to see each other.
//
// Everything it shows is a convenience. The form posts real fields to StoreSaleRequest, and
// CreateSale re-reads prices, re-checks stock, re-fingerprints the cart and reads the discount from
// the database. Nothing computed in this file is trusted by the server.
//
// The CSP-safe Alpine build evaluates only identifiers and literals inside attributes, so all
// configuration arrives through data attributes and every behaviour is a named method.
Alpine.data('recordSale', () => ({
    products: [],
    customers: [],
    today: '',
    endpoints: {},
    canRequestDiscount: false,

    // Buyer
    walkIn: false,
    customer: null,
    customerQuery: '',
    customerOpen: false,
    customerActive: -1,
    customerResults: [],
    customerBusy: false,
    customerSearchTicket: 0,

    // Cart
    lines: [],
    productQuery: '',
    productOpen: false,
    productActive: -1,
    productResults: [],
    productBusy: false,
    productSearchTicket: 0,

    // Payment and date
    paymentChoice: 'paid',
    amountInput: '0.00',
    saleDate: '',

    // Discount round trip
    discountOpen: false,
    discountAmount: '',
    discountReason: '',
    discountError: '',
    discountBusy: false,
    requestState: 'none',   // none | pending | approved | declined | abandoned
    requestId: null,
    draftId: null,
    approvedAmount: '0.00',
    requestedAt: null,
    elapsed: '',
    staleNotice: '',

    // Cart review and submission
    cartOpen: false,
    submitting: false,
    formError: '',

    // Sales left waiting for a decision, offered back on arrival.
    resumable: [],
    resumeOpen: false,

    init() {
        const data = this.$el.dataset;
        this.products = this.readJson(data.products);
        this.customers = this.readJson(data.customers);
        this.today = data.today || '';
        this.saleDate = data.today || '';
        this.canRequestDiscount = data.canRequestDiscount === '1';
        this.endpoints = {
            products: data.productsUrl || '',
            customers: data.customersUrl || '',
            draftStore: data.draftStoreUrl || '',
            draftStatus: data.draftStatusUrl || '',
            resumable: data.resumableUrl || '',
        };
        this.productResults = this.products;
        this.customerResults = this.customers;
        this.restore(this.readJson(data.restored));

        // A customer carried in from their profile, chosen only when there is nothing to restore:
        // a rejected submission's own entries always win, so arriving from a profile never
        // overwrites what the operator had already typed.
        const preselected = this.readJson(data.preselected);
        if (preselected && !this.customer && !this.isWalkIn) {
            this.chooseCustomer(preselected);
        }

        this.loadResumable();

        // A validation failure is a fresh page load, so `submitting` is already false. The one case
        // that is not is the back-forward cache, which can restore this page with the button still
        // showing "Recording sale…" and disabled. Clearing it on pageshow means the operator is
        // never left looking at a form that refuses to submit.
        this.onPageShow = () => { this.submitting = false; };
        window.addEventListener('pageshow', this.onPageShow);
    },

    // Puts a rejected submission back the way the operator left it.
    //
    // Everything here was rebuilt by the server from current records, so restoring is just copying:
    // this method never reconstructs a price, a stock figure or a product name from anything the
    // browser previously held. Line totals below are recomputed from the restored price and
    // quantity, so what is shown after an error is what the server would charge.
    restore(state) {
        if (!state || typeof state !== 'object') { this.addLine(); return; }

        this.walkIn = state.isWalkIn === true;
        // Never both: a walk-in has no customer, and a customer is not a walk-in.
        this.customer = this.walkIn ? null : (state.customer || null);

        this.lines = (state.lines || []).map((line, index) => ({
            key: 'restored-' + index,
            product: line.product,
            quantity: line.quantity,
        }));
        if (!this.lines.length) this.addLine();

        if (state.saleDate) this.saleDate = state.saleDate;
        if (state.saleDraftId) {
            // Carried back only so the sale can be re-submitted against the same approval. If the
            // cart, buyer or date has moved, CreateSale re-fingerprints and refuses it there.
            this.draftId = state.saleDraftId;
            this.requestState = 'approved';
        }

        // The payment status is inferred from the amount against the restored total rather than
        // trusted from the old input, so the tab can never contradict the figure beside it.
        if (typeof state.amountPaid === 'string' && state.amountPaid !== '') {
            this.amountInput = state.amountPaid;
            const paid = this.kobo(state.amountPaid);
            this.paymentChoice = paid <= 0 ? 'unpaid' : (paid >= this.totalKobo ? 'paid' : 'partial');
        }
    },

    // ── Resuming a saved sale ───────────────────────────────────────────────────────────────────
    // "Save as pending" is not a local draft: the SaleDraft and its discount request were written to
    // the database when the request was sent, so leaving the page loses nothing. This asks the
    // server which of the user's own carts are still unspent and offers them back.
    async loadResumable() {
        if (!this.endpoints.resumable) return;
        try {
            const response = await fetch(this.endpoints.resumable, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) return;
            const body = await response.json();
            this.resumable = body.drafts || [];
        } catch (error) {
            // Nothing to offer is a perfectly ordinary state; the page works exactly as before.
        }
    },

    get hasResumable() { return this.resumable.length > 0; },

    // Rebuilds the cart, buyer and trading day from the saved draft, so what gets recorded is the
    // sale the approval was granted against. The server re-fingerprints it regardless.
    resume(draft) {
        this.walkIn = draft.is_walk_in;
        this.customer = draft.is_walk_in ? null : draft.customer;
        this.saleDate = draft.sale_date || this.today;
        this.lines = draft.lines.map((line, index) => ({
            key: 'resumed-' + index,
            product: {
                id: line.product_id,
                name: line.name,
                sku: line.sku,
                price: line.price,
                // Stock is not carried in the snapshot — it is a live figure, and a stale one would
                // be worse than none. The picker refreshes it if the line is re-chosen, and
                // CreateSale re-reads it under a row lock either way.
                stock: line.quantity,
                unit: '',
            },
            quantity: line.quantity,
        }));
        if (!this.lines.length) this.addLine();

        this.requestId = draft.discount_request_id;
        this.draftId = draft.sale_draft_id;
        this.discountAmount = draft.requested_amount;
        this.discountReason = draft.reason || '';
        this.requestedAt = draft.requested_at ? Date.parse(draft.requested_at) : Date.now();
        this.staleNotice = '';
        this.resumeOpen = false;

        if (draft.status === 'approved') {
            this.approvedAmount = draft.requested_amount;
            this.requestState = 'approved';
            this.openCart();
        } else if (draft.status === 'declined') {
            this.requestState = 'declined';
            this.openCart();
        } else {
            this.requestState = 'pending';
            this.startClock();
            this.startPolling();
        }

        this.syncPaidAmount();
    },

    openResume() { this.resumeOpen = true; },
    closeResume() { this.resumeOpen = false; },

    resumeLabel(draft) {
        const who = draft.is_walk_in ? 'Walk-in customer' : (draft.customer ? draft.customer.name : 'Customer');
        return who + ' · ' + this.money(this.kobo(draft.subtotal));
    },

    destroy() {
        this.stopPolling();
        this.stopClock();
        window.removeEventListener('pageshow', this.onPageShow);
    },

    readJson(raw) {
        try { return JSON.parse(raw || '[]'); } catch (error) { return []; }
    },

    // ── Money ───────────────────────────────────────────────────────────────────────────────────
    // Kept in integer kobo so repeated addition cannot drift the way floats do. The server still
    // recomputes every figure in BCMath; this only has to agree with what the user is reading.
    kobo(value) {
        const clean = String(value ?? '').replace(/[^0-9.]/g, '');
        if (clean === '') return 0;
        const [naira, fraction = ''] = clean.split('.');
        return Number(naira || 0) * 100 + Number((fraction + '00').slice(0, 2));
    },

    // For reading. Grouped with thousands separators, so it is only ever used inside money().
    naira(kobo) {
        const sign = kobo < 0 ? '-' : '';
        const absolute = Math.abs(Math.round(kobo));
        return sign + String(Math.floor(absolute / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
            + '.' + String(absolute % 100).padStart(2, '0');
    },

    // For submitting. A plain decimal with no grouping, because this value goes into a real form
    // field and the server validates it as `decimal:0,2` — a separator would be rejected outright.
    // Keeping the two renderings apart is the point: a display formatter must never reach the wire.
    decimalAmount(kobo) {
        const sign = kobo < 0 ? '-' : '';
        const absolute = Math.abs(Math.round(kobo));
        return sign + String(Math.floor(absolute / 100)) + '.' + String(absolute % 100).padStart(2, '0');
    },

    money(kobo) { return '₦' + this.naira(kobo); },

    // ── Customer ────────────────────────────────────────────────────────────────────────────────
    get customerRows() { return this.customerResults; },

    customerInput() {
        this.customerOpen = true;
        this.customerActive = -1;
        this.searchCustomers();
    },

    // Focusing the empty field offers the list; focusing it with a customer already chosen must
    // not, because the combobox is only on screen at all when there is no selection. Keeping this
    // separate from `customerInput` means returning focus to the page cannot reopen a list the
    // operator has just finished with.
    customerFocus() {
        if (this.customer !== null || this.walkIn) return;
        this.customerInput();
    },

    // Closing is a click outside the whole combobox — the input and its list together — rather than
    // the input's own blur. A blur fires while the pointer is still down on an option, which would
    // tear the list out of the document before the selection could land.
    closeCustomerList() {
        this.customerOpen = false;
        this.customerActive = -1;
    },

    searchCustomers() {
        const term = this.customerQuery.trim();
        window.clearTimeout(this.customerTimer);
        this.customerTimer = window.setTimeout(() => this.fetchCustomers(term), 180);
    },

    async fetchCustomers(term) {
        if (!this.endpoints.customers) return;
        // A sequence number, so a slow answer for "M" cannot land after a fast one for "Mi" and
        // put stale names back under the cursor. Cheaper than an AbortController and enough here:
        // the request is already in flight either way, this only decides whose answer is shown.
        const ticket = ++this.customerSearchTicket;
        this.customerBusy = true;
        try {
            const url = this.endpoints.customers + '?q=' + encodeURIComponent(term);
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (ticket !== this.customerSearchTicket) return;
            if (!response.ok) return;
            const body = await response.json();
            if (ticket !== this.customerSearchTicket) return;
            this.customerResults = body.customers || [];
        } catch (error) {
            // A failed lookup leaves the last list in place; the field still submits what is chosen.
        } finally {
            // Only the newest request owns the busy flag, or an overtaken one would clear it while
            // its successor is still running.
            if (ticket === this.customerSearchTicket) this.customerBusy = false;
        }
    },

    moveCustomer(step) {
        if (!this.customerOpen) { this.customerOpen = true; return; }
        const count = this.customerRows.length;
        if (!count) return;
        this.customerActive = (this.customerActive + step + count) % count;
    },

    chooseCustomerAtCursor(event) {
        if (!this.customerOpen) return;
        const row = this.customerRows[this.customerActive];
        if (!row) return;
        event.preventDefault();
        this.chooseCustomer(row);
    },

    // The one way a registered customer is chosen, for both mouse and keyboard. Setting `customer`
    // and clearing `walkIn` together is what keeps the two buyer identities mutually exclusive —
    // the Sale has exactly one, and the database CHECK enforces the same shape.
    chooseCustomer(row) {
        this.customer = row;
        this.walkIn = false;
        this.customerQuery = '';
        this.customerOpen = false;
        this.customerActive = -1;
        this.invalidateApproval('customer');
    },

    clearCustomer() {
        this.customer = null;
        this.invalidateApproval('customer');
        this.$nextTick(() => this.$refs.customerInput?.focus());
    },

    chooseWalkIn() {
        this.walkIn = true;
        this.customer = null;
        this.customerQuery = '';
        this.customerOpen = false;
        this.invalidateApproval('customer');
    },

    clearWalkIn() {
        this.walkIn = false;
        this.invalidateApproval('customer');
    },

    get buyerChosen() { return this.walkIn || this.customer !== null; },

    get buyerLabel() {
        if (this.walkIn) return 'Walk-in customer';
        return this.customer ? this.customer.name : '';
    },

    // ── Cart ────────────────────────────────────────────────────────────────────────────────────
    addLine() {
        this.lines.push({key: 'line-' + Date.now() + '-' + this.lines.length, product: null, quantity: '1'});
    },

    removeLine(index) {
        this.lines.splice(index, 1);
        if (!this.lines.length) this.addLine();
        this.invalidateApproval('cart');
    },

    openProductPicker(index) {
        this.pickerIndex = index;
        this.productQuery = '';
        this.productResults = this.products;
        this.productOpen = true;
        this.productActive = -1;
        this.$nextTick(() => this.$refs.productInput?.focus());
    },

    productInput() {
        this.productOpen = true;
        this.productActive = -1;
        const term = this.productQuery.trim();
        window.clearTimeout(this.productTimer);
        this.productTimer = window.setTimeout(() => this.fetchProducts(term), 180);
    },

    async fetchProducts(term) {
        if (!this.endpoints.products) return;
        // Same sequencing as the customer search: the newest query owns the list.
        const ticket = ++this.productSearchTicket;
        this.productBusy = true;
        try {
            const url = this.endpoints.products + '?product_search=' + encodeURIComponent(term);
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (ticket !== this.productSearchTicket) return;
            if (!response.ok) return;
            const body = await response.json();
            if (ticket !== this.productSearchTicket) return;
            this.productResults = body.products || [];
        } catch (error) {
            // Leaves the previous list visible rather than emptying the picker on a network blip.
        } finally {
            if (ticket === this.productSearchTicket) this.productBusy = false;
        }
    },

    moveProduct(step) {
        if (!this.productOpen) { this.productOpen = true; return; }
        const count = this.productResults.length;
        if (!count) return;
        this.productActive = (this.productActive + step + count) % count;
    },

    chooseProductAtCursor(event) {
        if (!this.productOpen) return;
        const row = this.productResults[this.productActive];
        if (!row) return;
        event.preventDefault();
        this.chooseProduct(row);
    },

    chooseProduct(row) {
        const line = this.lines[this.pickerIndex];
        if (line) line.product = row;
        this.productOpen = false;
        this.productActive = -1;
        this.invalidateApproval('cart');
    },

    closeProductPicker() { this.productOpen = false; this.productActive = -1; },

    // A named method, not an expression in the attribute: the CSP-safe build resolves identifiers
    // only against the component, so a bare `Number(...)` in markup would throw at evaluation.
    outOfStock(row) { return Number(row.stock) <= 0; },

    // Quantity. The input is a real number field; these only nudge it, and the server is still the
    // authority on whether the resulting quantity can be sold.
    step(index, direction) {
        const line = this.lines[index];
        if (!line) return;
        const next = Math.max(1, (Number(line.quantity) || 0) + direction);
        line.quantity = String(next);
        this.invalidateApproval('cart');
    },

    quantityChanged() { this.invalidateApproval('cart'); },

    lineTotal(line) {
        if (!line.product) return 0;
        return this.kobo(line.product.price) * (Number(line.quantity) || 0);
    },

    // The inline warning from the Figma. Advisory only: CreateSale re-reads stock under a row lock,
    // so a quantity that becomes unsellable between here and submission is still refused there.
    overStock(line) {
        if (!line.product) return false;
        return (Number(line.quantity) || 0) > Number(line.product.stock);
    },

    get anyOverStock() { return this.lines.some((line) => this.overStock(line)); },

    get filledLines() { return this.lines.filter((line) => line.product && Number(line.quantity) > 0); },

    get subtotalKobo() {
        return this.filledLines.reduce((sum, line) => sum + this.lineTotal(line), 0);
    },

    get discountKobo() {
        return this.requestState === 'approved' ? this.kobo(this.approvedAmount) : 0;
    },

    get totalKobo() { return Math.max(0, this.subtotalKobo - this.discountKobo); },

    // ── Payment ─────────────────────────────────────────────────────────────────────────────────
    choosePayment(choice) {
        this.paymentChoice = choice;
        if (choice === 'paid') this.amountInput = this.decimalAmount(this.totalKobo);
        if (choice === 'unpaid') this.amountInput = '0.00';
        if (choice === 'partial' && this.kobo(this.amountInput) >= this.totalKobo) this.amountInput = '0.00';
    },

    // Keeps a "paid in full" sale honest when the cart or an approval changes the total underneath
    // it. Partial and unpaid amounts are left exactly as typed.
    syncPaidAmount() {
        if (this.paymentChoice === 'paid') this.amountInput = this.decimalAmount(this.totalKobo);
    },

    get amountKobo() { return this.kobo(this.amountInput); },

    get balanceKobo() { return Math.max(0, this.totalKobo - this.amountKobo); },

    get overpaying() { return this.amountKobo > this.totalKobo; },

    // ── Sale date ───────────────────────────────────────────────────────────────────────────────
    get futureDate() { return this.saleDate !== '' && this.saleDate > this.today; },

    get saleDateLabel() {
        if (this.saleDate === '') return '';
        const [year, month, day] = this.saleDate.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        if (Number.isNaN(date.getTime())) return this.saleDate;
        return date.toLocaleDateString(undefined, {weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'});
    },

    dateChanged() { this.invalidateApproval('date'); },

    // ── Validation ──────────────────────────────────────────────────────────────────────────────
    get problems() {
        const found = [];
        if (!this.buyerChosen) found.push('Choose a customer, or record this as a walk-in sale.');
        if (!this.filledLines.length) found.push('Add at least one product.');
        if (this.anyOverStock) found.push('One or more lines ask for more stock than is available.');
        if (this.saleDate === '') found.push('Choose the date this sale happened.');
        if (this.futureDate) found.push('A sale cannot be dated in the future.');
        if (this.overpaying) found.push('Amount paid cannot be more than the sale total.');
        if (this.paymentChoice === 'partial' && this.amountKobo <= 0) found.push('Enter the part payment received.');
        return found;
    },

    get ready() { return this.problems.length === 0; },

    // ── Discount round trip ─────────────────────────────────────────────────────────────────────
    // An approved discount is tied to an exact cart, buyer and sale date. When any of those change
    // the approval no longer describes this sale, so it is dropped here and the user is told why.
    // This is a courtesy, not a control: CreateSale re-fingerprints the cart it is actually given
    // and refuses a mismatch regardless of what this component believes.
    invalidateApproval(reason) {
        this.$nextTick(() => this.syncPaidAmount());
        if (this.requestState === 'none' || this.requestState === 'abandoned') return;
        const wording = {
            cart: 'the items changed',
            customer: 'the customer changed',
            date: 'the sale date changed',
        };
        this.staleNotice = 'The approved discount no longer applies because ' + (wording[reason] || 'the sale changed')
            + '. Request a new discount if you still need one.';
        this.stopPolling();
        this.stopClock();
        this.requestState = 'none';
        this.requestId = null;
        this.draftId = null;
        this.approvedAmount = '0.00';
    },

    openDiscount() {
        if (!this.canRequestDiscount || !this.ready) return;
        this.discountError = '';
        this.discountAmount = '';
        this.discountReason = '';
        this.discountOpen = true;
        this.$nextTick(() => this.$refs.discountAmount?.focus());
    },

    closeDiscount() {
        this.discountOpen = false;
        this.$nextTick(() => this.$refs.discountTrigger?.focus());
    },

    get discountPreviewKobo() { return Math.max(0, this.subtotalKobo - this.kobo(this.discountAmount)); },

    get discountValid() {
        const asked = this.kobo(this.discountAmount);
        return asked > 0 && asked <= this.subtotalKobo && this.discountReason.trim().length >= 10;
    },

    async sendDiscountRequest() {
        if (!this.discountValid || this.discountBusy) return;
        this.discountBusy = true;
        this.discountError = '';
        try {
            const response = await fetch(this.endpoints.draftStore, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: JSON.stringify({
                    amount: this.discountAmount,
                    reason: this.discountReason,
                    is_walk_in: this.walkIn,
                    customer_id: this.customer ? this.customer.id : null,
                    sale_date: this.saleDate,
                    products: this.filledLines.map((line) => ({product_id: line.product.id, quantity: line.quantity})),
                }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.discountError = this.firstError(body) || 'The discount request could not be sent.';
                return;
            }
            this.requestId = body.discount_request_id ?? null;
            this.draftId = body.sale_draft_id ?? null;
            this.requestState = 'pending';
            this.requestedAt = Date.now();
            this.staleNotice = '';
            this.discountOpen = false;
            this.startClock();
            this.startPolling();
        } catch (error) {
            this.discountError = 'The discount request could not be sent. Check your connection and try again.';
        } finally {
            this.discountBusy = false;
        }
    },

    firstError(body) {
        if (typeof body?.message === 'string' && !body.errors) return body.message;
        const errors = body?.errors || {};
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? first[0] : null;
    },

    // Polling, not a socket: this deployment is shared hosting. Every four seconds is responsive
    // enough for someone waiting at a counter and gentle on the server, and it stops the moment the
    // answer arrives, the wait is abandoned, or the page goes away.
    startPolling() {
        this.stopPolling();
        if (this.requestId === null) return;
        this.poller = window.setInterval(() => this.pollOnce(), 4000);
        this.pollOnce();
    },

    stopPolling() {
        if (this.poller) window.clearInterval(this.poller);
        this.poller = null;
    },

    async pollOnce() {
        if (this.requestState !== 'pending' || this.requestId === null) { this.stopPolling(); return; }
        try {
            const url = this.endpoints.draftStatus.replace('__ID__', String(this.requestId));
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) return;
            const body = await response.json();
            if (body.status === 'approved') {
                this.approvedAmount = body.requested_amount || '0.00';
                this.requestState = 'approved';
                this.stopPolling();
                this.stopClock();
                this.syncPaidAmount();
                this.openCart();
            }
            if (body.status === 'declined') {
                this.requestState = 'declined';
                this.stopPolling();
                this.stopClock();
                this.openCart();
            }
        } catch (error) {
            // A dropped poll is not an answer. The interval simply tries again.
        }
    },

    // "Continue without discount": the pending request is left on record for the Admin to see, but
    // this sale stops waiting for it and is recorded at its original total. Nothing is cancelled or
    // rewritten, and because no draft id is submitted, no discount can be applied.
    continueWithoutDiscount() {
        this.stopPolling();
        this.stopClock();
        this.requestState = 'abandoned';
        this.draftId = null;
        this.approvedAmount = '0.00';
        this.syncPaidAmount();
    },

    // "Save as pending": the request and its cart are already persisted, so this only has to stop
    // this page waiting and clear the form. The sale comes back under "Resume a saved sale", which
    // reads it from the database rather than from anything held here.
    saveAsPending() {
        this.stopPolling();
        this.stopClock();
        this.requestState = 'none';
        this.requestId = null;
        this.draftId = null;
        this.approvedAmount = '0.00';
        this.walkIn = false;
        this.customer = null;
        this.lines = [];
        this.addLine();
        this.saleDate = this.today;
        this.paymentChoice = 'paid';
        this.amountInput = '0.00';
        this.staleNotice = '';
        this.loadResumable();
    },

    startClock() {
        this.stopClock();
        this.tick();
        this.clock = window.setInterval(() => this.tick(), 1000);
    },

    stopClock() {
        if (this.clock) window.clearInterval(this.clock);
        this.clock = null;
    },

    tick() {
        if (!this.requestedAt) { this.elapsed = ''; return; }
        const seconds = Math.floor((Date.now() - this.requestedAt) / 1000);
        const minutes = Math.floor(seconds / 60);
        this.elapsed = minutes > 0
            ? minutes + 'm ' + String(seconds % 60).padStart(2, '0') + 's'
            : seconds + 's';
    },

    get waiting() { return this.requestState === 'pending'; },

    // The cart modal's one visual state, derived rather than tracked.
    //
    // Reading `requestState` directly from three separate x-show expressions let the template
    // express combinations the domain does not have — an approved *and* declined cart, for
    // instance. Collapsing it to a single string here means the modal asks one question and gets
    // exactly one answer, so the three branches cannot overlap however the component is driven.
    //
    // 'approved' and 'declined' are the only outcomes that change what the cart looks like;
    // pending, abandoned and none all present as an ordinary cart.
    get cartState() {
        if (this.requestState === 'approved') return 'approved';
        if (this.requestState === 'declined') return 'declined';
        return 'normal';
    },

    // A cart with nothing in it has no total to show and nothing to sell, so the modal is not
    // reachable at all until at least one line carries a product and a positive quantity.
    get cartHasItems() { return this.filledLines.length > 0; },

    // ── Cart review and submission ──────────────────────────────────────────────────────────────
    // The single door into the cart modal. Every path that wants to show the cart — the Review
    // button, an approval landing, a resumed draft — goes through here, so the "never open an empty
    // cart" rule is stated once instead of being remembered at each call site.
    openCart() {
        if (!this.cartHasItems) return;
        this.cartOpen = true;
    },

    closeCart() {
        this.cartOpen = false;
        this.$nextTick(() => this.$refs.reviewTrigger?.focus());
    },

    // The form owns submission so the page still works without this component: the button is a real
    // submit button and every field below is a real input. This only guards against a second click
    // while the first request is in flight; the server's own idempotency remains the real defence.
    submit(event) {
        if (this.submitting) { event.preventDefault(); return; }
        if (!this.ready) { event.preventDefault(); this.formError = this.problems[0]; return; }
        this.submitting = true;
    },

    submitFromCart() {
        if (!this.cartHasItems) return;
        this.cartOpen = false;
        this.$nextTick(() => this.$refs.form.requestSubmit());
    },

    // What actually posts. Quantities and ids only — never a price, a total or a discount amount.
    get postedLines() {
        return this.filledLines.map((line) => ({product_id: line.product.id, quantity: line.quantity}));
    },

    get postedDraftId() {
        return this.requestState === 'approved' && this.draftId !== null ? this.draftId : '';
    },
}));

// The Sale complete modal. Open on arrival, and dismissible: closing it leaves a clean Record Sale
// page ready for the next sale, which is what the counter actually does next.
Alpine.data('saleComplete', () => ({
    open: true,

    init() {
        // Focus enters the dialog rather than staying on the form behind it, so a keyboard user is
        // not left tabbing through a page they cannot see.
        this.$nextTick(() => this.focusable()[0]?.focus());
    },

    focusable() {
        return [...(this.$refs.panel?.querySelectorAll('a[href], button:not([disabled])') ?? [])]
            .filter((element) => element.getClientRects().length);
    },

    // Keeps Tab inside the dialog while it is open. The same wrap-around the navigation drawer
    // uses, written out here because this panel is its own focus scope.
    containFocus(event) {
        const controls = this.focusable();
        if (!controls.length) return;
        const first = controls[0];
        const last = controls[controls.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        }

        if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },

    // Closing finishes the sale: the operator lands on the Sales list, where a banner confirms
    // what was recorded. The Sale is already saved, so this navigation cannot resubmit anything —
    // it is a GET to a different page.
    close() {
        this.open = false;
        const done = this.$el.dataset.doneUrl;

        if (done) {
            window.location.assign(done);
            return;
        }

        // No destination configured: just dismiss, and drop the completion state from the address
        // bar so a refresh does not re-announce a finished sale.
        window.history.replaceState({}, '', window.location.pathname);
    },
}));

// ── Sales list ──────────────────────────────────────────────────────────────────────────────────
// Row selection and the right-hand panel. The row carries its own data in a data attribute, so
// opening a sale needs no request; the line items and the correction form are fetched on demand.
//
// One explicit mode rather than several booleans, so the panel cannot be in two states at once:
//
//   null       closed
//   details    the sale, read-only
//   correction the correction form, fetched from the server
//
// Nothing here mutates a Sale. The correction form posts to the existing endpoint, which owns the
// policy, the eligibility check and the audit.
// The Export menu on the Sales list: two formats behind one button.
//
// Both entries are ordinary links to routes that read the current query string, so this adds a
// choice of format and nothing else — the export itself is the server's business. It exists only to
// open, close and hand focus back, which is the part a plain <a> cannot do on its own.
Alpine.data('salesExportMenu', () => ({
    open: false,

    toggle() {
        this.open = !this.open;
        // Opening from the keyboard should land on the first format, not leave focus behind on a
        // button whose menu has just appeared underneath it.
        if (this.open) this.$nextTick(() => this.$el.querySelector('[role="menuitem"]')?.focus());
    },

    // Escape, a click outside, and following either link all arrive here. Focus returns to the
    // trigger so keyboard users are not dropped at the top of the document.
    close() {
        if (!this.open) return;
        this.open = false;
        if (this.$el.contains(document.activeElement)) this.$refs.trigger?.focus();
    },
}));

// The Return form inside the Sales side panel.
//
// Selection, quantity and a settlement preview — nothing that decides money. Every figure it shows
// is recomputed by RecordSaleReturn under a row lock before anything is written, and the quantity
// ceilings here are the server's own remaining quantities, re-derived and re-enforced there.
Alpine.data('returnPanel', () => ({
    lines: [],
    picked: [],
    quantities: {},
    reason: '',
    disposition: 'restock',
    previewUrl: '',
    // Supplied by the server, never derived here.
    summary: '',
    clauses: [],
    summaryError: '',
    summaryTicket: 0,
    submitting: false,
    error: '',

    init() {
        try {
            this.lines = JSON.parse(this.$el.dataset.lines) || [];
        } catch (error) {
            this.lines = [];
        }
        this.previewUrl = this.$el.dataset.previewUrl || '';

        // Any change to the selection, a quantity or the disposition re-asks the server what the
        // return would now settle to, so the sentence always describes the current choice.
        this.$watch('picked', () => this.refreshSummary());
        this.$watch('quantities', () => this.refreshSummary());
        this.$watch('disposition', () => this.refreshSummary());
    },

    lineOf(id) {
        return this.lines.find((line) => Number(line.id) === Number(id));
    },

    isPicked(id) {
        return this.picked.map(Number).includes(Number(id));
    },

    quantityOf(id) {
        // One unit is the sensible opening position for a return, and the common case.
        return this.quantities[id] ?? 1;
    },

    maxOf(id) {
        const line = this.lineOf(id);
        return line ? Number(line.remaining) : 0;
    },

    atMin(id) { return this.quantityOf(id) <= 1; },
    atMax(id) { return this.quantityOf(id) >= this.maxOf(id); },

    step(id, by) {
        const next = this.quantityOf(id) + by;
        if (next < 1 || next > this.maxOf(id)) return;
        // Replaced rather than mutated in place: `$watch` on an object compares references, so an
        // in-place write would change the quantity without the summary ever hearing about it.
        this.quantities = {...this.quantities, [id]: next};
    },

    // The settlement is never computed here.
    //
    // Money in Inventra is decimal, summed with bcmath on the server. Repeating that sum in
    // JavaScript would be a second definition of the split — in binary floating point — free to
    // disagree with the ledger over fractions of a naira, and free to drift if the rule ever
    // changes on one side only. So the browser reports what the operator chose and the server says
    // what it means, through the same ReturnSettlementPreview the recorded return agrees with.
    //
    // A sequence guard, so a slow answer for an earlier selection cannot land under a later one.
    async refreshSummary() {
        const chosen = this.picked.map((id) => ({
            sale_item_id: Number(id),
            quantity: String(this.quantityOf(id)),
        }));

        if (!chosen.length) {
            this.summary = '';
            this.clauses = [];
            this.summaryError = '';
            return;
        }

        const ticket = ++this.summaryTicket;
        const params = new URLSearchParams();
        chosen.forEach((line, index) => {
            params.append('items[' + index + '][sale_item_id]', line.sale_item_id);
            params.append('items[' + index + '][quantity]', line.quantity);
        });
        params.append('restock', this.disposition === 'restock' ? '1' : '0');

        try {
            const response = await fetch(this.previewUrl + '?' + params.toString(), {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
            });
            if (ticket !== this.summaryTicket) return;

            // A followed redirect to the login page arrives as an ordinary 200, so it is caught
            // before its body is mistaken for the JSON that was asked for.
            if (response.redirected || !response.ok) {
                this.summary = '';
                this.clauses = [];
                this.summaryError = 'The effect of this return could not be calculated.';
                return;
            }

            const body = await response.json();
            if (ticket !== this.summaryTicket) return;
            this.summary = body.sentence || '';
            this.clauses = body.clauses || [];
            this.summaryError = '';
        } catch (error) {
            if (ticket === this.summaryTicket) {
                this.summary = '';
                this.clauses = [];
                this.summaryError = 'The effect of this return could not be calculated.';
            }
        }
    },

    get valid() {
        if (!this.picked.length || !this.reason) return false;
        return this.picked.every((id) => {
            const quantity = this.quantityOf(id);
            return quantity >= 1 && quantity <= this.maxOf(id);
        });
    },
}));

// The Reports dashboard: which period is shown, and how tall each bar is drawn.
//
// It computes no money. Every amount arrives from ReportsDashboard already summed, netted and
// formatted; this works out bar heights as a percentage of the tallest, picks axis ticks, and
// decides which of the three prepared periods to display. Switching tabs shows data already on the
// page, so there is no request and nothing to wait for.
Alpine.data('reportsDashboard', () => ({
    periods: {},
    active: 'week',
    selected: null,

    tabs: [
        {key: 'week', label: 'Week'},
        {key: 'month', label: 'Month'},
        {key: 'year', label: 'Year'},
    ],

    init() {
        try {
            this.periods = JSON.parse(this.$el.dataset.periods) || {};
        } catch (error) {
            this.periods = {};
        }
    },

    // A method rather than two statements in the attribute: the CSP-safe Alpine build evaluates a
    // single expression and refuses a statement list outright, which silently breaks the tabs.
    choose(key) {
        this.active = key;
        this.selected = null;
    },

    highlight(index) {
        this.selected = index;
    },

    clearHighlight() {
        this.selected = null;
    },

    // Axis labels, highest first, matching the order they are stacked in.
    get ticks() {
        const period = this.periods[this.active] || {bars: []};
        const tallest = (period.bars || []).reduce(
            (highest, bar) => Math.max(highest, Number(bar.value) || 0), 0
        );
        const ceiling = this.axisFor(tallest);
        // A period with no revenue still gets a baseline label, so the chart reads as an empty
        // scale rather than a broken one.
        if (!ceiling) return ['0'];

        return [4, 3, 2, 1, 0].map((multiple) => this.short(ceiling / 4 * multiple));
    },

    // The active period, with each bar's drawn height and display amount worked out once.
    get period() {
        const period = this.periods[this.active] || {bars: [], footer: [], products: []};
        const tallest = (period.bars || []).reduce(
            (highest, bar) => Math.max(highest, Number(bar.value) || 0), 0
        );
        // Heights are drawn against the same rounded ceiling the axis labels describe, so a bar
        // reaching the ₦210k label really is at ₦210k.
        const axis = this.axisFor(tallest);

        return {
            ...period,
            key: this.active,
            totalDisplay: this.short(Number(period.total) || 0),
            bars: (period.bars || []).map((bar) => ({
                ...bar,
                display: this.short(Number(bar.value) || 0),
                // A month still to come is a short neutral stub — enough to show the slot exists,
                // never tall enough to read as revenue. A real zero stays flat on the baseline, so
                // a day with no trade is visibly empty rather than looking like a small one.
                height: bar.future ? 14 : (axis > 0 ? (Number(bar.value) || 0) / axis * 100 : 0),
            })),
        };
    },

    // The ceiling the bars are drawn against: the tallest bar rounded up to something a person
    // would choose for an axis, so the tick labels read ₦70k/₦140k/₦210k rather than ₦67.3k.
    // Four equal steps, each a round multiple, giving five labels including zero.
    axisFor(tallest) {
        if (tallest <= 0) return 0;
        // A tenth of the tallest bar as headroom, then rounded up to a step a person would pick.
        // Without the cap the ceiling can land a whole step above the data and leave the chart
        // looking half empty, which is what a plain round-up to the next magnitude does.
        const target = tallest * 1.1;
        const magnitude = Math.pow(10, Math.floor(Math.log10(target)));
        const step = Math.ceil(target / 4 / (magnitude / 4)) * (magnitude / 4);
        return step * 4;
    },

    // Abbreviation for chart labels only — 268k, 1.24M. The authoritative figures are the server's;
    // this is never used for anything that is added up or submitted.
    short(amount) {
        if (amount >= 1000000) return this.trim(amount / 1000000) + 'M';
        if (amount >= 1000) return this.trim(amount / 1000) + 'k';
        return this.trim(amount);
    },

    trim(value) {
        return String(Math.round(value * 100) / 100);
    },

    // Which bar reads as active: whatever is hovered or focused, and otherwise the period the
    // reader is currently in, which the server marked. So the chart opens with today's day, this
    // week or this month already highlighted rather than looking uniformly flat.
    get activeIndex() {
        if (this.selected !== null) return this.selected;

        const bars = this.period.bars || [];
        const current = bars.findIndex((bar) => bar.current);
        return current === -1 ? null : current;
    },

    get chartLabel() {
        const period = this.period;
        return 'Revenue by ' + (this.active === 'week' ? 'day' : this.active === 'month' ? 'week' : 'month')
            + ', ' + (period.bars || []).map((bar) => bar.label + ' ' + bar.display).join(', ');
    },
}));

// The customer form: photo preview, and the "WhatsApp same as phone" toggle.
//
// Presentation only. Which number is stored, whether it is canonical, whether the phone is already
// taken and whether consent was given are all decided by CustomerProfileRequest on the server; this
// shows a chosen photograph before it is uploaded and reveals the alternate-number field when it is
// relevant.
// Copies a value already rendered on the page.
//
// Used by the one-time staff credential reveal. It reads the text that is already on screen and
// puts it on the clipboard — it does not fetch, regenerate or store anything, so the plaintext
// gains no new home. The confirmation resets itself so the button does not sit claiming "Copied"
// indefinitely.
// The Add Staff role cards.
//
// Presentation only: the real radio remains the source of truth and what the form submits, and the
// server still validates the value against the role enum. This mirrors the checked state onto the
// card so the whole card can show as selected.
Alpine.data('roleCards', () => ({
    chosen: '',

    init() {
        this.chosen = this.$el.querySelector('input[type=radio]:checked')?.value ?? '';
    },
}));

Alpine.data('copyValue', () => ({
    copied: false,
    timer: null,

    async copy() {
        const field = this.$refs.value;
        if (!field) return;

        try {
            await navigator.clipboard.writeText(field.value);
        } catch (error) {
            // A denied or unavailable clipboard — an insecure origin, or a browser that refuses
            // without a gesture it recognises. Selecting the text lets the operator copy it by
            // hand rather than leaving them with a button that silently did nothing.
            field.focus();
            field.select();
            return;
        }

        this.copied = true;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { this.copied = false; }, 2500);
    },

    destroy() {
        clearTimeout(this.timer);
    },
}));

Alpine.data('customerForm', () => ({
    previewUrl: '',
    sameAsPhone: true,

    init() {
        // An existing customer with a separate WhatsApp number opens with the box unticked, so the
        // field they already filled in is visible rather than hidden behind a default.
        this.sameAsPhone = this.$el.dataset.separateWhatsapp !== '1';
    },

    // Shows the chosen file before it is uploaded. The object URL is revoked when it is replaced,
    // so choosing several files in a row does not leak them.
    preview(event) {
        const file = event.target.files && event.target.files[0];
        if (!file) return;

        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = URL.createObjectURL(file);
    },

    destroy() {
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    },

    // Removing a photograph is its own request, never a side effect of saving the form: a form
    // submitted without a file means "leave the photo alone". Built and posted here because a
    // <form> cannot be nested inside another one.
    removePhoto() {
        const action = this.$el.dataset.removePhoto;
        if (!action) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = action;

        for (const [name, value] of [['_token', this.$el.dataset.csrf], ['_method', 'DELETE']]) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    },
}));

Alpine.data('salesList', () => ({
    panelMode: null,
    selected: null,
    sale: null,
    lines: [],
    linesLoading: false,
    linesError: '',
    correctionHtml: '',
    // The element the fetched form is written into, handed over by its own `x-init`.
    mount: null,
    correctionLoading: false,
    correctionError: '',
    linesTicket: 0,
    correctionTicket: 0,

    init() {
        // A correction rejected by validation comes back as a fresh page render. The server marks
        // which Sale it was, so the panel reopens on that sale with the operator's entries intact
        // rather than dropping them on the floor.
        const reopen = this.$el.dataset.reopenCorrection;
        if (reopen) this.$nextTick(() => this.reopenCorrection(reopen));
    },

    reopenCorrection(publicId) {
        const row = this.$el.querySelector(`tr[data-sale*="${publicId}"]`);
        if (!row) return;
        this.applyRow(row);
        this.openCorrection();
    },

    // Reads the clicked row's own data rather than taking it through an attribute expression, which
    // the CSP-safe build could not evaluate anyway.
    selectRow(event) {
        const row = event.target.closest('tr[data-sale]');
        if (!row) return;

        const data = this.rowData(row);
        if (!data) return;

        // Clicking the open sale again closes the panel, which is the least surprising toggle.
        if (this.selected === data.id && this.panelMode === 'details') { this.closePanel(); return; }

        this.applyRow(row);
        this.panelMode = 'details';
        this.$nextTick(() => this.$refs.panel?.focus?.());
    },

    // The pencil. Opens the correction form in the panel instead of navigating away; the anchor
    // stays a real link so the full page still works without JavaScript.
    correctRow(event) {
        event.preventDefault();
        event.stopPropagation();
        const row = event.target.closest('tr[data-sale]');
        if (!row) return;

        this.applyRow(row);
        this.openCorrection();
    },

    rowData(row) {
        try { return JSON.parse(row.dataset.sale); } catch (error) { return null; }
    },

    applyRow(row) {
        const data = this.rowData(row);
        if (!data) return;
        this.selected = data.id;
        this.sale = data;
        this.correctionHtml = '';
        this.correctionError = '';
        this.loadLines();
    },

    async openCorrection() {
        if (!this.sale?.returnPanelUrl) return;
        this.panelMode = 'correction';
        this.correctionLoading = true;
        // Every attempt starts from a clean slate, so a message or a form left over from the last
        // one cannot show alongside this one's. Exactly one body state is ever true.
        this.correctionError = '';
        this.correctionHtml = '';
        if (this.mount) {
            window.Alpine?.destroyTree?.(this.mount);
            this.mount.innerHTML = '';
            delete this.mount.dataset.mounted;
        }
        const ticket = ++this.correctionTicket;

        try {
            const response = await fetch(this.sale.returnPanelUrl, {
                headers: {Accept: 'text/html'},
                credentials: 'same-origin',
            });
            if (ticket !== this.correctionTicket) return;

            // A session that has lapsed answers with a redirect to the login page, and `fetch`
            // follows it silently — the reply then arrives as a perfectly ordinary 200 whose body
            // is a whole HTML document. Injected as a fragment, its <html>/<body> wrapper is
            // discarded and the panel is left blank, which is exactly the failure this guards. A
            // redirected reply is never the fragment that was asked for.
            if (response.redirected) {
                this.correctionError = 'Your session has expired. Reload the page and sign in again.';
                return;
            }

            if (!response.ok) {
                // A Sale that has become ineligible since the list was drawn, or a role that may
                // not correct it. Either way the server decided, and the panel says so in its own
                // words — never by echoing an exception back to the screen.
                this.correctionError = response.status === 403
                    ? 'You do not have permission to record a return on this sale.'
                    : 'Goods cannot be returned against this sale.';
                return;
            }

            const html = await response.text();
            if (ticket !== this.correctionTicket) return;

            // A fragment that parses to nothing would leave the panel blank with no explanation.
            // Better to say so than to show an empty box.
            if (!html.trim()) {
                this.correctionError = 'The form could not be loaded. Try again.';
                return;
            }

            this.correctionHtml = html;
            // The mount point is inside the correction branch's `x-if`, so it may not exist yet on
            // the first pass. Its own `x-init` calls back once it does; this covers the case where
            // it is already present, such as loading a second sale's form without leaving the mode.
            this.$nextTick(() => {
                this.mountCorrection();
                this.$refs.panel?.focus?.();
            });
        } catch (error) {
            if (ticket === this.correctionTicket) this.correctionError = 'Unable to load the return form.';
        } finally {
            if (ticket === this.correctionTicket) this.correctionLoading = false;
        }
    },

    // Inserts the fetched fragment and initialises Alpine on it.
    //
    // `x-html` cannot be used here: it sets innerHTML and stops, so an `x-data` arriving inside the
    // fragment is never initialised and every directive under it stays inert — a blank panel with
    // no error to explain it. `Alpine.initTree` walks the newly inserted nodes the way Alpine walks
    // the document at startup, so the return form's own component comes alive.
    //
    // The element is passed in rather than looked up. It lives inside a `<template x-if>`, which is
    // its own Alpine scope, so an `x-ref` declared there never reaches this component's `$refs` —
    // the lookup returned undefined, the guard below swallowed it, and the panel rendered blank
    // with nothing to explain why. `$el` is handed straight to us and cannot be scoped away.
    mountCorrection(mount) {
        this.mount = mount || this.mount;
        if (!this.mount || !this.correctionHtml) return;
        // Already mounted, so leave the live component alone rather than rebuilding it under the
        // operator's half-filled form.
        if (this.mount.dataset.mounted === this.correctionHtml.length.toString()) return;

        window.Alpine?.destroyTree?.(this.mount);
        this.mount.innerHTML = this.correctionHtml;
        this.mount.dataset.mounted = this.correctionHtml.length.toString();
        window.Alpine?.initTree?.(this.mount);
    },

    showDetails() {
        this.panelMode = 'details';
        this.correctionHtml = '';
        // Alpine keeps references to what it initialised, so the subtree is destroyed rather than
        // merely emptied — otherwise each visit would leave another live component behind.
        if (this.mount) {
            window.Alpine?.destroyTree?.(this.mount);
            this.mount.innerHTML = '';
            delete this.mount.dataset.mounted;
        }
        // The node is discarded with the `x-if` branch, so the handle must not outlive it.
        this.mount = null;
    },

    async loadLines() {
        this.lines = [];
        this.linesError = '';
        this.linesLoading = true;
        // A sequence guard, so a slow answer for one sale cannot land under another.
        const ticket = ++this.linesTicket;

        try {
            const response = await fetch(this.sale.linesUrl, {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
            });
            if (ticket !== this.linesTicket) return;

            // As above: a followed redirect to the login page reads as a 200, so it is caught
            // before its body is mistaken for the JSON that was asked for.
            if (response.redirected) {
                this.linesError = 'Your session has expired. Reload the page and sign in again.';
                return;
            }

            if (!response.ok) {
                // The sale's own figures are already on screen from the row; only the breakdown is
                // missing, and the panel says so with a Retry rather than showing an empty list.
                this.linesError = response.status === 403
                    ? 'You do not have permission to see these items.'
                    : 'The items could not be loaded.';
                return;
            }

            const body = await response.json();
            if (ticket !== this.linesTicket) return;
            this.lines = body.lines || [];
        } catch (error) {
            // A network failure, not a server verdict. Never surfaced as a raw exception message.
            if (ticket === this.linesTicket) this.linesError = 'The items could not be loaded.';
        } finally {
            if (ticket === this.linesTicket) this.linesLoading = false;
        }
    },

    get statusLabel() {
        if (!this.sale) return '';
        const status = this.sale.paymentStatus;
        return status.charAt(0).toUpperCase() + status.slice(1);
    },

    closePanel() {
        this.panelMode = null;
        this.selected = null;
        this.sale = null;
        this.lines = [];
        this.linesError = '';
        this.correctionHtml = '';
        this.correctionError = '';
    },
}));

// ── WhatsApp Automation ─────────────────────────────────────────────────────────────────────────
// The module's whole front end: the connection flow, the automation switches, the template editor
// and its live preview.
//
// Nothing here decides anything the server owns. The connection advances only when the server says
// a code was sent and then that it was verified; a switch reflects what the server persisted, and
// reverts if the write fails; the preview is a local render for the operator's eyes, while the
// message a customer receives is always rendered server-side from the saved template.
//
// CSP-safe: every attribute expression is a method call or a plain property read.
Alpine.data('whatsappAutomation', () => ({
    automations: [],
    samples: {},
    recipientOptions: [],
    preview: '',
    nowLabel: '',
    connect: { open: false, step: 1, phone: '', pin: '', connectedPhone: '', error: '', busy: false, preparing: false, config: null, state: null },
    editor: {
        open: false, id: null, key: '', title: '', trigger: '', body: '', enabled: false,
        delayHours: 24, chips: {}, recipients: [], readonly: false,
        templateName: '', templateLanguage: 'en', templateStatus: 'Not submitted', templateApproved: false,
        saving: false, testing: false, testResult: '', testOk: false, error: '',
    },

    init() {
        this.automations = this.readJson('wa-automations', []);
        this.samples = this.readJson('wa-samples', {});
        this.recipientOptions = this.readJson('wa-recipient-options', []);
        this.nowLabel = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    // The server's data arrives as JSON in a script tag rather than in an attribute, so nothing a
    // template body contains can break out into markup.
    readJson(id, fallback) {
        try {
            return JSON.parse(document.getElementById(id)?.textContent || 'null') ?? fallback;
        } catch (error) {
            return fallback;
        }
    },

    find(id) {
        return this.automations.find((automation) => automation.id === id);
    },

    initials(name) {
        return (name || '').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
    },

    async post(url, body, method = 'POST') {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { payload = {}; }
        return { ok: response.ok, status: response.status, payload };
    },

    // The first validation message the server returned, whatever field it belongs to. Server
    // wording is used verbatim: it is the authority on why something was refused.
    firstError(payload, fallback) {
        const errors = payload?.errors;
        if (errors) {
            const key = Object.keys(errors)[0];
            if (key && errors[key]?.[0]) return errors[key][0];
        }
        return payload?.message || fallback;
    },

    // ── Connection ──────────────────────────────────────────────────────────────────────────────
    // Step 1 collects intent only. Step 2 asks the server for a one-time state and hands over to
    // Meta's Embedded Signup, which is the only way a business's WhatsApp sending identity can be
    // granted; the browser then passes Meta's short-lived code, the ids Meta reported and the
    // number's two-step PIN to the server, which proves everything with Meta itself. Nothing in
    // this file decides that a connection succeeded, and no secret is ever held here.
    openConnect() {
        this.connectOpener = document.activeElement;
        this.connect.open = true;
        this.connect.step = 1;
        this.connect.error = '';
        this.connect.pin = '';
        this.connect.connectedPhone = '';
        this.$nextTick(() => this.$refs.connectPhone?.focus());
    },

    closeConnect() {
        this.connect.open = false;
        this.connect.busy = false;
        this.connect.pin = '';
        this.connect.state = null;
        this.connectOpener?.focus?.();
    },

    backToPhone() {
        this.connect.step = 1;
        this.connect.error = '';
    },

    // Step 1 to 2. The state and Meta's SDK are prepared now, not on the Meta button: Meta's code
    // is valid for 30 seconds, and a popup opened after an await can be blocked by the browser.
    continueToMeta() {
        this.connect.error = '';
        this.connect.step = 2;
        this.prepareSignup();
    },

    // A fresh server-issued state for one attempt, plus the values Meta's SDK needs client-side.
    // The app secret and verify token never reach the browser.
    async prepareSignup() {
        this.connect.preparing = true;
        this.connect.state = null;

        const result = await this.post('/whatsapp/automation/connection/start', {});

        if (!result.ok || !result.payload?.configured || !result.payload?.state) {
            this.connect.preparing = false;
            this.connect.error = this.firstError(result.payload, 'Unable to start Meta connection. WhatsApp is not configured for this installation.');
            return;
        }

        this.connect.config = result.payload;
        this.connect.state = result.payload.state;

        if (!(await this.metaSdk())) {
            this.connect.state = null;
            this.connect.error = "We couldn't load Meta's sign-in. Check your connection and try again.";
        }

        this.connect.preparing = false;
    },

    // Loads Meta's SDK once, initialised with the configured app and Graph version.
    async metaSdk() {
        if (window.FB) return window.FB;

        const loaded = await new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = 'https://connect.facebook.net/en_US/sdk.js';
            script.async = true;
            script.crossOrigin = 'anonymous';
            script.onload = () => resolve(true);
            script.onerror = () => resolve(false);
            document.head.appendChild(script);
        });

        if (!loaded || !window.FB) return null;

        window.FB.init({
            appId: this.connect.config.app_id,
            version: this.connect.config.graph_version,
            autoLogAppEvents: true,
            xfbml: false,
        });

        return window.FB;
    },

    // Step 2. Runs Embedded Signup synchronously from the click, then sends the result to the
    // server. One attempt at a time; a spent state is replaced before another can start.
    startEmbeddedSignup() {
        if (this.connect.busy || this.connect.preparing) return;
        this.connect.error = '';

        if (!/^\d{6}$/.test(this.connect.pin)) {
            this.connect.error = "Enter the number's six-digit two-step verification PIN.";
            return;
        }

        if (!window.FB || !this.connect.state) {
            this.connect.error = 'The connection is still being prepared. Try again in a moment.';
            if (!this.connect.preparing) this.prepareSignup();
            return;
        }

        this.connect.busy = true;
        const state = this.connect.state;

        // Meta reports the chosen WABA and number through a window message, separately from the
        // login callback that carries the code, so the listener is armed before login opens.
        let session = {};
        const onMessage = (event) => {
            let host = '';
            try { host = new URL(event.origin).hostname; } catch (error) { return; }
            if (!/(^|\.)facebook\.com$/.test(host)) return;
            try {
                const data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
                if (data.event === 'FINISH') session = data.data || {};
                else if (data.event === 'CANCEL') session = { cancelled: true };
                else session = { unsupported: true };
            } catch (error) { /* not a signup message */ }
        };
        window.addEventListener('message', onMessage);

        window.FB.login((response) => {
            window.removeEventListener('message', onMessage);
            const code = response && response.authResponse ? response.authResponse.code : null;

            if (!code || !session.waba_id || !session.phone_number_id) {
                this.connect.busy = false;
                // Cancelling is not a failure; the operator simply closed Meta's window.
                this.connect.error = session.cancelled ? ''
                    : (session.unsupported || (code && !session.phone_number_id)
                        ? 'Choose a WhatsApp Business Account and a phone number in Meta to connect.'
                        : "We couldn't complete the WhatsApp connection.");
                return;
            }

            this.finishConnection(state, code, session);
        }, {
            config_id: this.connect.config.config_id,
            response_type: 'code',
            override_default_response_type: true,
            extras: { setup: {} },
        });
    },

    // The server proves the account with Meta, subscribes and registers it, and only then
    // connects it. Step 3 shows the number Meta returned rather than the one typed in step 1.
    async finishConnection(state, code, session) {
        const pin = this.connect.pin;
        this.connect.pin = '';
        // The state is spent by this request whatever the outcome.
        this.connect.state = null;

        const result = await this.post('/whatsapp/automation/connection/complete', {
            state,
            code,
            waba_id: String(session.waba_id),
            phone_number_id: String(session.phone_number_id),
            pin,
        });
        this.connect.busy = false;

        if (!result.ok) {
            this.connect.error = this.firstError(result.payload, "We couldn't complete the WhatsApp connection.");
            this.prepareSignup();
            return;
        }

        this.connect.connectedPhone = result.payload.phone || '';
        this.connect.step = 3;
    },

    // The page is reloaded so every card re-renders from the server's connection state rather than
    // this component guessing at what the connected page looks like.
    finishConnect() {
        window.location.reload();
    },

    trapConnect(event) {
        this.trap(event, this.$refs.connectPanel, this.connect.open);
    },

    trapEditor(event) {
        this.trap(event, this.$refs.editorPanel, this.editor.open);
    },

    trap(event, panel, open) {
        if (!open || !panel) return;
        const controls = [...panel.querySelectorAll('button, a[href], input, select, textarea')]
            .filter((element) => element.getClientRects().length && !element.disabled);
        if (!controls.length) return;
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },

    // ── Automation switches ─────────────────────────────────────────────────────────────────────
    // The checkbox is optimistic, but never dishonest: if the server refuses, it is put back, so it
    // can never show enabled while the database says otherwise.
    async toggle(id, input) {
        const desired = input.checked;
        const result = await this.post('/whatsapp/automation/' + id + '/toggle', { enabled: desired });

        if (!result.ok) {
            input.checked = !desired;
            return;
        }

        input.checked = result.payload.enabled;
        const automation = this.find(id);
        if (automation) automation.enabled = result.payload.enabled;
        window.location.reload();
    },

    async saveTiming(id, hours) {
        const automation = this.find(id);
        if (!automation) return;
        await this.post('/whatsapp/automation/' + id, {
            body: automation.body, delay_hours: Number(hours),
        }, 'PUT');
        automation.delayHours = Number(hours);
    },

    // ── Editor ──────────────────────────────────────────────────────────────────────────────────
    openEditor(id) {
        this.loadEditor(id, false);
    },

    openPreview(id) {
        this.loadEditor(id, true);
    },

    loadEditor(id, readonly) {
        const automation = this.find(id);
        if (!automation) return;
        this.editorOpener = document.activeElement;
        this.editor = {
            open: true,
            id: automation.id,
            key: automation.key,
            title: automation.title,
            trigger: automation.trigger,
            body: automation.body,
            enabled: automation.enabled,
            delayHours: automation.delayHours ?? 24,
            chips: automation.chips || {},
            recipients: [...(automation.recipients || [])],
            readonly,
            templateName: automation.templateName || '',
            templateLanguage: automation.templateLanguage || 'en',
            templateStatus: automation.templateStatus || 'Not submitted',
            templateApproved: !!automation.templateApproved,
            saving: false, testing: false, testResult: '', testOk: false, error: '',
        };
        this.syncPreview();
        this.$nextTick(() => {
            if (!readonly) this.$refs.body?.focus();
        });
    },

    closeEditor() {
        this.editor.open = false;
        this.editorOpener?.focus?.();
    },

    // The local preview render. Substitutes only the allowlisted tokens this automation offers,
    // mirroring the server's renderer — the server's version is what a customer actually receives.
    syncPreview() {
        let text = this.editor.body || '';
        Object.keys(this.editor.chips || {}).forEach((token) => {
            const value = this.samples[token] ?? '';
            text = text.split('{{' + token + '}}').join(value).split('{{ ' + token + ' }}').join(value);
        });
        this.preview = text;
    },

    insertFirstChip() {
        const first = Object.keys(this.editor.chips || {})[0];
        if (first) this.insertChip(first);
    },

    // Inserts the token at the caret, which is what makes the chips feel like the Figma's inline
    // variables rather than an append-only list.
    insertChip(token) {
        const field = this.$refs.body;
        const marker = '{{' + token + '}}';
        if (!field) { this.editor.body += marker; this.syncPreview(); return; }
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        this.editor.body = field.value.slice(0, start) + marker + field.value.slice(end);
        this.syncPreview();
        this.$nextTick(() => {
            field.focus();
            const caret = start + marker.length;
            field.setSelectionRange(caret, caret);
        });
    },

    async saveEditor() {
        if (this.editor.saving) return;
        this.editor.saving = true;
        this.editor.error = '';
        const result = await this.post('/whatsapp/automation/' + this.editor.id, {
            body: this.editor.body,
            enabled: this.editor.enabled,
            delay_hours: this.editor.delayHours,
            recipients: this.editor.recipients,
        }, 'PUT');
        this.editor.saving = false;

        if (!result.ok) {
            this.editor.error = this.firstError(result.payload, 'The message could not be saved.');
            return;
        }

        window.location.reload();
    },

    async sendTest() {
        if (this.editor.testing) return;
        this.editor.testing = true;
        this.editor.testResult = '';
        const result = await this.post('/whatsapp/automation/' + this.editor.id + '/test', {
            body: this.editor.body,
        });
        this.editor.testing = false;
        // Whatever actually happened, including a provider refusal — never an assumed success.
        this.editor.testOk = result.ok;
        this.editor.testResult = result.ok
            ? (result.payload.message || 'Test message sent to your number.')
            : this.firstError(result.payload, 'The test message could not be sent.');
    },
}));

// ── Profile photo panel ─────────────────────────────────────────────────────────────────────────
// Reveals the existing upload form. The form and its validation are unchanged; this only decides
// whether it is on screen, so the Figma header stays clean without losing the capability.
Alpine.data('profilePhoto', () => ({
    open: false,
    toggle() { this.open = !this.open; },
}));

// Opens the shell's logout dialog from anywhere on the page. A component rather than an inline
// expression because the CSP-safe Alpine build refuses `new` inside an attribute.
Alpine.data('logoutTrigger', () => ({
    ask() { window.dispatchEvent(new CustomEvent('open-logout')); },
}));

// ── Global navbar search ────────────────────────────────────────────────────────────────────────
// Type-ahead over customers and products. The server decides what may be returned — each half is
// behind its own policy — so this component only debounces, renders and handles keyboard movement.
//
// Requests are debounced and superseded: a stale response that arrives after a newer one is
// discarded by sequence number, so fast typing can never leave older results on screen.
Alpine.data('globalSearch', () => ({
    query: '',
    open: false,
    loading: false,
    customers: [],
    products: [],
    searched: [],
    canCreateCustomer: false,
    createUrl: '',
    active: -1,
    timer: null,
    sequence: 0,

    init() {
        this.url = this.$refs.input.dataset.searchUrl;
        this.createBase = this.$refs.input.dataset.customerCreateUrl || '';
    },

    get flat() {
        return [
            ...this.customers.map((c, i) => ({
                key: 'c' + i, type: 'customer', name: c.name,
                meta: c.phone || '', initials: c.initials, url: c.url,
            })),
            ...this.products.map((p, i) => ({
                key: 'p' + i, type: 'product', name: p.name,
                // Price and SKU on one line, exactly as the design reads them.
                meta: [p.price, p.sku].filter(Boolean).join(' · '),
                inStock: p.inStock,
                stockLabel: p.inStock ? p.stock + ' in stock' : 'Out of stock',
                url: p.url,
            })),
        ];
    },

    get hasResults() {
        return this.flat.length > 0;
    },

    // Truthful copy: it names what was actually searched rather than always claiming "products".
    get emptyTitle() {
        const what = this.searched.length === 2 ? 'results' : (this.searched[0] === 'customers' ? 'customers' : 'products');
        return 'No ' + what + " match '" + this.query + "'";
    },

    get emptyHint() {
        return this.searched.includes('products')
            ? 'Try a different name or SKU.'
            : 'Try a different name or phone number.';
    },

    schedule() {
        clearTimeout(this.timer);
        const term = this.query.trim();

        if (term.length < 2) {
            this.customers = [];
            this.products = [];
            this.open = false;
            this.loading = false;
            return;
        }

        this.open = true;
        this.loading = true;
        // Debounced so a request is not fired on every keystroke.
        this.timer = setTimeout(() => this.fetch(term), 220);
    },

    async fetch(term) {
        const ticket = ++this.sequence;

        try {
            const response = await fetch(this.url + '?q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('search failed');
            const data = await response.json();

            // A superseded request must not overwrite newer results.
            if (ticket !== this.sequence) return;

            this.customers = data.customers ?? [];
            this.products = data.products ?? [];
            this.searched = data.searched ?? [];
            this.canCreateCustomer = Boolean(data.canCreateCustomer);
            this.createUrl = this.canCreateCustomer && this.createBase
                ? this.createBase + '?name=' + encodeURIComponent(term)
                : '';
            this.active = -1;
        } catch {
            if (ticket !== this.sequence) return;
            this.customers = [];
            this.products = [];
        } finally {
            if (ticket === this.sequence) this.loading = false;
        }
    },

    move(step) {
        if (!this.open || !this.hasResults) return;
        const count = this.flat.length;
        this.active = (this.active + step + count) % count;
    },

    choose() {
        const item = this.flat[this.active];
        if (item) window.location.href = item.url;
    },

    clear() {
        clearTimeout(this.timer);
        this.sequence++;
        this.query = '';
        this.customers = [];
        this.products = [];
        this.active = -1;
        this.loading = false;
        this.open = false;
        this.$refs.input?.focus();
    },

    close() {
        if (!this.open) return;
        this.open = false;
        this.active = -1;
    },
}));

// ── Notification dropdown ───────────────────────────────────────────────────────────────────────
// Opens the bell's preview. The rows themselves are server-rendered and already scoped to this
// operator, so this owns nothing but open/closed state and focus return.
Alpine.data('notificationMenu', () => ({
    open: false,

    toggle() {
        this.open = !this.open;
        if (this.open) this.$nextTick(() => this.$el.querySelector('a, button[type=submit]')?.focus());
    },

    close() {
        if (!this.open) return;
        this.open = false;
        if (this.$el.contains(document.activeElement)) this.$refs.trigger?.focus();
    },
}));

// ── Dashboard revenue chart ─────────────────────────────────────────────────────────────────────
// The bars and their heights are server-rendered; this only positions the tooltip, so the chart
// needs no library and works with JavaScript unavailable (minus the tooltip).
Alpine.data('dashboardChart', () => ({
    open: false,
    tip: '',
    amount: '',
    style: '',

    show(bar) {
        this.tip = bar.dataset.tip;
        this.amount = bar.dataset.amount;
        const plot = bar.closest('.dash-chart-plot').getBoundingClientRect();
        const rect = bar.getBoundingClientRect();
        // Positioned against the plot so the tooltip cannot drift outside the card.
        this.style = 'left:' + (rect.left - plot.left + rect.width / 2) + 'px; bottom:' + (plot.bottom - rect.top + 8) + 'px;';
        this.open = true;
    },

    hide() {
        this.open = false;
    },
}));

// ── Staff row action menu ───────────────────────────────────────────────────────────────────────
// The ellipsis menu on each Staff & roles row.
//
// It is a view of the server's decision, never a second one: the menu's entries are rendered only
// where the policy already allowed them, so an unauthorised action is absent from the DOM rather
// than merely hidden. This component owns nothing but open/closed state and focus, which is the
// part markup cannot do alone.
//
// Each row owns its own instance, so opening one closes any other: a single `open-staff-menu` event
// carries the opener's id and every other instance stands down when it does not match.
Alpine.data('staffRowMenu', (id = '') => ({
    open: false,
    id,

    toggle() {
        if (this.open) { this.close(); return; }
        // Tell every sibling row to close before this one opens; two menus at once would leave the
        // operator unsure which row an entry belongs to.
        window.dispatchEvent(new CustomEvent('open-staff-menu', { detail: this.id }));
        this.open = true;
        // Opening from the keyboard lands on the first entry rather than leaving focus on a trigger
        // whose menu has just appeared beneath it.
        this.$nextTick(() => this.$el.querySelector('[role="menuitem"]')?.focus());
    },

    // A sibling opened: stand down without stealing focus, which still belongs to that row.
    standDown(event) {
        if (event.detail !== this.id) this.open = false;
    },

    // Escape, a click outside, and choosing an entry all arrive here. Focus returns to the trigger
    // so a keyboard user is not dropped at the top of the document.
    close() {
        if (!this.open) return;
        this.open = false;
        if (this.$el.contains(document.activeElement)) this.$refs.trigger?.focus();
    },
}));

// ── Business logo panel ─────────────────────────────────────────────────────────────────────────
// Reveals the existing upload form; the form and its validation are unchanged.
Alpine.data('businessLogo', () => ({
    open: false,
    toggle() { this.open = !this.open; },
}));

// ── Logout confirmation ─────────────────────────────────────────────────────────────────────────
// A gate in front of the existing POST logout, not a replacement for it: the form and its CSRF
// token do the signing out, and this only decides whether the operator meant to.
Alpine.data('logoutConfirm', () => ({
    open: false,
    submitting: false,

    show() {
        this.opener = document.activeElement;
        this.open = true;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const cancel = this.$refs.cancel ?? this.$el.querySelector('.lo-actions button');
            (cancel ?? this.$refs.panel)?.focus();
        }));
    },

    close() {
        if (this.submitting) return;
        this.open = false;
        this.opener?.focus?.();
    },

    // Guards a double click or repeated Enter from posting twice.
    submit() {
        if (this.submitting) return false;
        this.submitting = true;
        return true;
    },

    trapFocus(event) {
        if (!this.open) return;
        const controls = [...this.$refs.panel.querySelectorAll('button')]
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
    // Selects and date inputs both: the Sales filter strip has no Apply button, so changing any
    // control is what submits the form.
    if ((control instanceof HTMLSelectElement || control instanceof HTMLInputElement) && control.form) {
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
