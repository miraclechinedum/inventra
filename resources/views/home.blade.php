{{--
    Public homepage. Standalone layout: it deliberately does not extend the
    authenticated app-layout, so no application navigation or user context is
    ever exposed here.

    Every figure inside the product mockups is hard-coded decorative copy. This
    page runs no queries and reads no business data — see the "sample data"
    badge on the preview frame and the caption beneath it.
--}}
@php
    $icon = fn (string $name) => asset("images/figma/icon-{$name}.svg");
    $check = asset('images/figma/auth-check.svg');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inventra — Run your business with clarity, from stock to sale</title>
    <meta name="description" content="Inventra keeps inventory, sales, customers, expenses and day-to-day operations connected in one simple workspace built for growing trading and retail businesses.">
    <meta name="theme-color" content="#1d4ed8">
    {{-- Self-hosted display face for this page. Emits the @font-face rules and
         preloads from the Vite fonts pipeline, and carries the CSP nonce. --}}
    @fonts(['space-grotesk'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="inventra-home">
<a href="#hero" class="home-skip">Skip to content</a>

{{-- ═══════════════════════════════════════════════════════════ navbar ═══ --}}
<header class="home-nav" data-home-nav>
    <div class="home-shell home-nav-inner">
        <a href="{{ url('/') }}" class="home-brand" aria-label="Inventra home">
            <img src="{{ asset('images/figma/inventra-logo.png') }}" alt="Inventra" width="116" height="32">
        </a>

        {{-- COMMENTED OUT: section links removed from the navbar.
        <nav class="home-nav-links" aria-label="Homepage sections">
            <a href="#features">Features</a>
            <a href="#how-it-works">How it works</a>
            <a href="#built-for-business">Built for business</a>
        </nav>
        --}}

        <div class="home-nav-actions">
            {{-- COMMENTED OUT: Sign in removed from the navbar.
            <a href="{{ route('login') }}" class="home-nav-signin">Sign in</a>
            --}}
            <a href="{{ route('login') }}" class="home-btn home-btn-primary home-btn-sm">Get started</a>
        </div>
    </div>

    {{-- COMMENTED OUT: with no section links left there is nothing to collapse,
         so the burger and its panel would only duplicate Get started.
    <button type="button" class="home-menu-toggle" data-home-menu-toggle
            aria-expanded="false" aria-controls="home-mobile-menu" aria-label="Open menu">
        <span></span><span></span><span></span>
    </button>
    <div class="home-mobile-panel" id="home-mobile-menu">
        <nav aria-label="Homepage sections">
            <a href="#features">Features</a>
            <div class="home-mobile-actions">
                <a href="{{ route('login') }}" class="home-btn home-btn-ghost">Sign in</a>
                <a href="{{ route('login') }}" class="home-btn home-btn-primary">Get started</a>
            </div>
        </nav>
    </div>
    --}}
</header>

{{-- ═════════════════════════════════════════════════════════════ hero ═══ --}}
<main id="hero">
    <section class="home-hero" aria-labelledby="hero-title">
        <div class="home-shell home-hero-copy">
            <p class="home-hero-badge"><b>Inventra</b> Business operations, in one workspace</p>
            <h1 id="hero-title">Run your business with clarity.<span>From stock to sale.</span></h1>
            <p>Inventory, sales, customers, expenses and business insights — connected in one simple workspace built for growing businesses.</p>
            <div class="home-hero-ctas">
                <a href="{{ route('login') }}" class="home-btn home-btn-primary">
                    Get started
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h9M8.5 4.5 12 8l-3.5 3.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="#features" class="home-btn home-btn-ghost">Explore features</a>
            </div>
            <p class="home-hero-note">Role-based access for administrators, managers and sales representatives.</p>
        </div>

        {{-- ── product preview ─────────────────────────────────────────── --}}
        <div class="home-shell-wide home-stage">
            <figure class="home-frame" role="img"
                    aria-label="Preview of the Inventra dashboard showing sample key figures, a daily sales chart, a low-stock list and recent sales. All figures shown are sample data.">
                <div class="home-frame-bar">
                    <span class="home-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span class="home-url">
                        <svg width="11" height="11" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 7V5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><rect x="3.5" y="7" width="9" height="6" rx="1.6" stroke="currentColor" stroke-width="1.5"/></svg>
                        <span>inventra.app/dashboard</span>
                    </span>
                    <span class="home-preview-tag">Product preview · sample data</span>
                </div>

                <div class="home-mock" aria-hidden="true">
                    <aside class="home-mock-side">
                        <img src="{{ asset('images/figma/inventra-logo.png') }}" alt="">
                        <p>Workspace</p>
                        <ul class="home-mock-nav">
                            <li class="is-active"><img src="{{ $icon('dashboard') }}" alt="">Dashboard</li>
                            <li><img src="{{ $icon('inventory') }}" alt="">Inventory</li>
                            <li><img src="{{ $icon('customers') }}" alt="">Customers</li>
                            <li><img src="{{ $icon('sales') }}" alt="">Sales</li>
                        </ul>
                        <p>Operations</p>
                        <ul class="home-mock-nav">
                            <li><img src="{{ $icon('inventory') }}" alt="">Purchases</li>
                            <li><img src="{{ $icon('sales') }}" alt="">Expenses</li>
                            <li><img src="{{ $icon('dashboard') }}" alt="">Reports</li>
                        </ul>
                    </aside>

                    <div class="home-mock-main">
                        <div class="home-mock-top">
                            <b>Dashboard</b>
                            <span class="home-mock-top-right">
                                <span class="home-mock-chip">Today</span>
                                <span class="home-mock-cta">+ Record sale</span>
                            </span>
                        </div>

                        <div class="home-mock-body">
                            <div class="home-mock-kpis">
                                <div class="home-kpi">
                                    <i><img src="{{ $icon('sales') }}" alt=""></i>
                                    <span>Sales today</span><strong>₦186,400</strong>
                                    <em>12 sales recorded</em>
                                </div>
                                <div class="home-kpi">
                                    <i class="is-muted"><img src="{{ $icon('customers') }}" alt=""></i>
                                    <span>Outstanding</span><strong>₦42,500</strong>
                                    <em class="is-muted">7 open balances</em>
                                </div>
                                <div class="home-kpi">
                                    <i class="is-green"><img src="{{ $icon('inventory') }}" alt=""></i>
                                    <span>Products</span><strong>248</strong>
                                    <em>14 categories</em>
                                </div>
                                <div class="home-kpi">
                                    <i class="is-amber"><img src="{{ $icon('bell') }}" alt=""></i>
                                    <span>Low stock</span><strong>6</strong>
                                    <em class="is-amber">Needs restocking</em>
                                </div>
                            </div>

                            <div class="home-mock-split">
                                <div class="home-panel">
                                    <div class="home-panel-head"><b>Sales this week</b><small>Reports</small></div>
                                    <div class="home-chart">
                                        <i style="--h:44%"></i><i style="--h:62%"></i><i style="--h:38%"></i>
                                        <i style="--h:74%"></i><i style="--h:56%"></i><i class="is-peak" style="--h:92%"></i><i style="--h:48%"></i>
                                    </div>
                                    <div class="home-chart-axis">
                                        <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                                    </div>
                                </div>

                                <div class="home-panel">
                                    <div class="home-panel-head"><b>Low stock</b><small>Inventory</small></div>
                                    <ul class="home-stocklist">
                                        <li><span><b>Brake Pad Set</b><small>SKU-0481-YO</small></span><strong>2 left</strong></li>
                                        <li><span><b>Engine Oil 5L</b><small>SKU-9987-GW</small></span><strong>3 left</strong></li>
                                        <li><span><b>Air Filter</b><small>SKU-6649-XV</small></span><strong>4 left</strong></li>
                                        <li><span><b>Wiper Blade</b><small>SKU-2613-LI</small></span><strong>5 left</strong></li>
                                    </ul>
                                </div>
                            </div>

                            {{-- tabindex -1: the frame is decorative (aria-hidden), so its scroll container must not sit in the tab order --}}
                            <div class="home-mock-table" tabindex="-1">
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="home-sn">S/N</th><th>Sale</th><th>Customer</th>
                                            <th>Payment</th><th class="is-num">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td class="home-sn">1</td><td><b>SALE-000412</b></td><td>Adaeze Stores</td><td><span class="home-pill home-pill-paid">Paid</span></td><td class="is-num">₦68,000</td></tr>
                                        <tr><td class="home-sn">2</td><td><b>SALE-000411</b></td><td>Chidi Motors</td><td><span class="home-pill home-pill-partial">Partial</span></td><td class="is-num">₦45,900</td></tr>
                                        <tr><td class="home-sn">3</td><td><b>SALE-000410</b></td><td>Bola Hardware</td><td><span class="home-pill home-pill-paid">Paid</span></td><td class="is-num">₦31,250</td></tr>
                                        <tr><td class="home-sn">4</td><td><b>SALE-000409</b></td><td>Ngozi Traders</td><td><span class="home-pill home-pill-unpaid">Unpaid</span></td><td class="is-num">₦41,250</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </figure>

            <div class="home-float home-float-a" aria-hidden="true">
                <small>Receipt sent</small>
                <div class="home-float-row" style="margin-top:6px">
                    <span class="home-float-tick"><img src="{{ $icon('whatsapp') }}" alt=""></span>
                    <span><b>SALE-000412</b><p>Delivered to customer</p></span>
                </div>
            </div>

            <div class="home-float home-float-b" aria-hidden="true">
                <div class="home-float-row">
                    <span class="home-float-tick"><img src="{{ $check }}" alt=""></span>
                    <span><small>Stock updated</small><b>Brake Pad Set −4</b></span>
                </div>
                <p style="margin-top:8px">Movement recorded automatically</p>
            </div>
        </div>

        <p class="home-stage-caption">Product preview. All names and figures shown are sample data.</p>
    </section>

    {{-- ══════════════════════════════════════════ capability strip ═══ --}}
    <section class="home-capabilities" aria-labelledby="capabilities-title">
        <div class="home-shell">
            <h2 id="capabilities-title">Everything your day runs on</h2>
            <ul class="home-cap-grid">
                @foreach ([
                    ['inventory', 'Inventory'],
                    ['sales', 'Sales'],
                    ['customers', 'Customers'],
                    ['inventory', 'Purchases'],
                    ['sales', 'Expenses'],
                    ['dashboard', 'Reports'],
                ] as [$glyph, $label])
                    <li class="home-cap home-reveal" data-delay="{{ $loop->index % 4 }}">
                        <i><img src="{{ $icon($glyph) }}" alt="" width="19" height="19"></i>
                        <span>{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════ value section ═══ --}}
    <section class="home-value" aria-labelledby="value-title">
        <div class="home-shell home-value-grid">
            <div class="home-reveal">
                <p class="home-eyebrow">The daily reality</p>
                <h2 id="value-title" style="margin-top:16px;font-size:clamp(28px,3.4vw,42px);font-weight:700">Less paperwork. More control.</h2>
                <p style="margin-top:16px;font-size:17px">Most growing businesses already track everything they need to. It just lives in different places — a notebook behind the counter, a WhatsApp thread, a spreadsheet on someone's laptop, and a good memory.</p>
                <p style="margin-top:14px;font-size:17px">Inventra brings those daily records into one place, so the answer to “what did we sell, what's left, and who still owes us?” takes seconds rather than an evening.</p>

                <div class="home-value-points">
                    <div class="home-value-point">
                        <i><img src="{{ $icon('search') }}" alt="" width="18" height="18"></i>
                        <div><b>One place to look</b><p>Stock, sales, customers, purchases and expenses in a single workspace.</p></div>
                    </div>
                    <div class="home-value-point">
                        <i><img src="{{ $icon('staff') }}" alt="" width="18" height="18"></i>
                        <div><b>Recorded by the person doing the work</b><p>Sales representatives record as they sell. Nothing waits to be copied over later.</p></div>
                    </div>
                    <div class="home-value-point">
                        <i><img src="{{ $icon('dashboard') }}" alt="" width="18" height="18"></i>
                        <div><b>Consistent numbers</b><p>Every sale, payment and stock movement is captured the same way, every time.</p></div>
                    </div>
                </div>
            </div>

            <div class="home-reveal" data-delay="2">
                <div class="home-scatter" aria-hidden="true">
                    <div class="home-scatter-row">
                        <span class="home-note is-tilt-a">📓 <b>Counter notebook</b> — today's sales</span>
                        <span class="home-note is-tilt-b">💬 <b>WhatsApp</b> — “did Chidi pay?”</span>
                    </div>
                    <div class="home-scatter-row">
                        <span class="home-note is-tilt-c">📄 <b>Spreadsheet</b> — stock count (last month)</span>
                        <span class="home-note is-tilt-a">🧾 <b>Drawer</b> — supplier invoices</span>
                    </div>
                    <div class="home-scatter-row">
                        <span class="home-note is-tilt-b">🧠 <b>Memory</b> — who owes what</span>
                    </div>
                </div>

                <p class="home-value-arrow">Brought together</p>

                <div class="home-consolidated">
                    <b><img src="{{ asset('images/figma/auth-logo-mark.png') }}" alt="">One Inventra workspace</b>
                    <ul>
                        <li>Current stock &amp; movements</li>
                        <li>Sales &amp; receipts</li>
                        <li>Customer balances</li>
                        <li>Supplier purchases</li>
                        <li>Business expenses</li>
                        <li>Operational reports</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- ═════════════════════════════════════════ feature showcases ═══ --}}
    <section class="home-showcases" id="features" aria-labelledby="showcases-title">
        <div class="home-shell">
            <div class="home-section-head home-reveal">
                <p class="home-eyebrow">Features</p>
                <h2 id="showcases-title">Built around how trading businesses actually work</h2>
                <p>Three things decide whether a trading day went well: what you have, what you sold, and what it added up to.</p>
            </div>

            {{-- A. Inventory --}}
            <article class="home-showcase" style="margin-top:clamp(44px,5vw,72px)">
                <div class="home-reveal">
                    <p class="home-eyebrow">Inventory</p>
                    <h3>Know what's in stock.</h3>
                    <p>Every product carries its current stock, its category and its reorder level. When something runs low it surfaces on the dashboard instead of being discovered at the point of sale.</p>
                    <ul class="home-checks">
                        <li><i><img src="{{ $check }}" alt=""></i><span>Current stock per product, updated as you sell and receive</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Low-stock visibility against a reorder level you set</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>A dated movement history for every quantity change</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Categories, and supplier purchases that restock you</span></li>
                    </ul>
                </div>
                <div class="home-showcase-visual home-reveal" data-delay="2">
                    <div class="home-card" aria-hidden="true">
                        <div class="home-card-head">
                            <img src="{{ $icon('inventory') }}" alt=""><b>Inventory</b><span>248 products</span>
                        </div>
                        <div class="home-bar">
                            <div class="home-bar-row">
                                <div><b>Engine Oil 5L</b><small>SKU-9987-GW · Lubricants</small><span class="home-bar-track"><i class="is-low" style="--w:12%"></i></span></div>
                                <div class="home-bar-qty">3<small>reorder 10</small></div>
                            </div>
                            <div class="home-bar-row">
                                <div><b>Brake Pad Set</b><small>SKU-0481-YO · Braking</small><span class="home-bar-track"><i class="is-low" style="--w:9%"></i></span></div>
                                <div class="home-bar-qty">2<small>reorder 8</small></div>
                            </div>
                            <div class="home-bar-row">
                                <div><b>Alternator Belt</b><small>SKU-3312-QA · Engine</small><span class="home-bar-track"><i style="--w:68%"></i></span></div>
                                <div class="home-bar-qty">34<small>reorder 12</small></div>
                            </div>
                            <div class="home-bar-row">
                                <div><b>Spark Plug (4pc)</b><small>SKU-7741-KD · Ignition</small><span class="home-bar-track"><i style="--w:84%"></i></span></div>
                                <div class="home-bar-qty">62<small>reorder 15</small></div>
                            </div>
                        </div>
                        <div class="home-report-foot">
                            <img src="{{ $icon('bell') }}" alt="" width="14" height="14">
                            <span>2 products are at or below their reorder level</span>
                        </div>
                    </div>
                </div>
            </article>

            {{-- B. Sales --}}
            <article class="home-showcase is-flipped">
                <div class="home-reveal">
                    <p class="home-eyebrow">Sales &amp; payments</p>
                    <h3>Record sales in seconds.</h3>
                    <p>Pick the customer, add products and quantities, take what they paid. Inventra works out the balance, moves the stock and produces the receipt.</p>
                    <ul class="home-checks">
                        <li><i><img src="{{ $check }}" alt=""></i><span>Customer, products and quantities on one screen</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Full, part or no payment — the balance is tracked either way</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Later payments recorded against the original sale</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>A printable receipt, and delivery to the customer on WhatsApp</span></li>
                    </ul>
                </div>
                <div class="home-showcase-visual home-reveal" data-delay="2">
                    <div class="home-card" aria-hidden="true">
                        <div class="home-card-head">
                            <img src="{{ $icon('sales') }}" alt=""><b>Sale receipt</b><span>SALE-000412</span>
                        </div>
                        <div class="home-receipt">
                            <div class="home-receipt-head">
                                <b>Adaeze Stores</b>
                                <small>12 Market Road, Onitsha · Anambra</small>
                                <small>Sold by Grace O. · 8 Sep 2026, 2:14 PM</small>
                            </div>
                            <div class="home-receipt-lines">
                                <div><span>Engine Oil 5L × 2</span><span>₦24,000</span></div>
                                <div><span>Brake Pad Set × 4</span><span>₦38,000</span></div>
                                <div><span>Air Filter × 1</span><span>₦6,000</span></div>
                            </div>
                            <div class="home-receipt-total"><span>Total</span><span>₦68,000</span></div>
                            <div class="home-receipt-lines" style="padding-bottom:0">
                                <div><span>Paid (cash)</span><span>₦68,000</span></div>
                                <div><span>Balance</span><span>₦0</span></div>
                            </div>
                            <div class="home-receipt-foot">
                                <img src="{{ $icon('whatsapp') }}" alt="">
                                <span>Receipt delivered to the customer on WhatsApp</span>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            {{-- C. Business visibility --}}
            <article class="home-showcase">
                <div class="home-reveal">
                    <p class="home-eyebrow">Business visibility</p>
                    <h3>Know what your business is doing.</h3>
                    <p>Operational reports over any date range: what you sold, what you actually collected, what is still owed, and what you spent on stock and running costs.</p>
                    <ul class="home-checks">
                        <li><i><img src="{{ $check }}" alt=""></i><span>Sales and collections, by staff member or customer</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Outstanding receivables, so nothing is quietly forgotten</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Expenses and supplier purchases over the same period</span></li>
                        <li><i><img src="{{ $check }}" alt=""></i><span>Inventory movement and product performance reports</span></li>
                    </ul>
                    <p style="margin-top:20px;font-size:13.5px;color:#94a3b8">Operational reporting. Inventra does not calculate profit, cost of goods sold or tax.</p>
                </div>
                <div class="home-showcase-visual home-reveal" data-delay="2">
                    <div class="home-card" aria-hidden="true">
                        <div class="home-card-head">
                            <img src="{{ $icon('dashboard') }}" alt=""><b>Business summary</b><span>1–8 Sep</span>
                        </div>
                        <div class="home-report-grid">
                            <div class="home-report-tile"><small>Gross sales</small><strong>₦1,284,500</strong></div>
                            <div class="home-report-tile"><small>Collections</small><strong>₦1,109,300</strong></div>
                            <div class="home-report-tile"><small>Outstanding</small><strong>₦175,200</strong></div>
                            <div class="home-report-tile"><small>Expenses</small><strong>₦86,400</strong></div>
                        </div>
                        <div class="home-bar" style="padding-top:0">
                            <div class="home-bar-row">
                                <div><b>Grace O.</b><small>Sales representative</small><span class="home-bar-track"><i style="--w:78%"></i></span></div>
                                <div class="home-bar-qty">₦512k<small>38 sales</small></div>
                            </div>
                            <div class="home-bar-row">
                                <div><b>Emeka N.</b><small>Sales representative</small><span class="home-bar-track"><i style="--w:56%"></i></span></div>
                                <div class="home-bar-qty">₦368k<small>27 sales</small></div>
                            </div>
                            <div class="home-bar-row">
                                <div><b>Fatima B.</b><small>Manager</small><span class="home-bar-track"><i style="--w:61%"></i></span></div>
                                <div class="home-bar-qty">₦404k<small>31 sales</small></div>
                            </div>
                        </div>
                        <div class="home-report-foot">
                            <img src="{{ $icon('search') }}" alt="" width="14" height="14">
                            <span>Ten operational reports, filterable by date, staff, customer and product</span>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </section>

    {{-- ═════════════════════════════════════════════ feature grid ═══ --}}
    {{-- COMMENTED OUT: Capabilities (feature grid) section — hidden on the homepage.
    <section class="home-features" aria-labelledby="grid-title">
        <div class="home-shell">
            <div class="home-section-head home-reveal">
                <p class="home-eyebrow">Capabilities</p>
                <h2 id="grid-title">One workspace, eleven working parts</h2>
                <p>Each module is built for a specific job, and they all share the same records.</p>
            </div>

            <div class="home-feature-grid">
                <article class="home-feature is-lead home-reveal">
                    <i><img src="{{ $icon('inventory') }}" alt="" width="18" height="18"></i>
                    <b>Inventory management</b>
                    <p>Products, categories, current stock, reorder levels and a dated movement history behind every quantity change.</p>
                    <ul>
                        <li><i><img src="{{ $check }}" alt=""></i>Low-stock alerts on the dashboard</li>
                        <li><i><img src="{{ $check }}" alt=""></i>Stock adjusted by sales, returns and purchases</li>
                        <li><i><img src="{{ $check }}" alt=""></i>Per-product movement trail</li>
                    </ul>
                </article>

                <article class="home-feature is-wide home-reveal" data-delay="1">
                    <i><img src="{{ $icon('sales') }}" alt="" width="18" height="18"></i>
                    <b>Sales &amp; payments</b>
                    <p>Record a sale against a customer, take full or part payment, and collect the balance later. Every payment is tied to the sale it settles.</p>
                    <ul class="home-feature-list">
                        <li>Paid, partial and unpaid states per sale</li>
                        <li>Later payments recorded against the original sale</li>
                        <li>Printable receipts, and a payments history</li>
                    </ul>
                </article>

                <article class="home-feature home-reveal" data-delay="2">
                    <i><img src="{{ $icon('customers') }}" alt="" width="18" height="18"></i>
                    <b>Customer records</b>
                    <p>Contact details, purchase history and the current balance for each customer.</p>
                </article>

                <article class="home-feature home-reveal" data-delay="3">
                    <i><img src="{{ $icon('customers') }}" alt="" width="18" height="18"></i>
                    <b>Suppliers &amp; purchasing</b>
                    <p>Supplier records and stock receipts that bring purchased quantities into inventory.</p>
                </article>

                <article class="home-feature home-reveal">
                    <i><img src="{{ $icon('sales') }}" alt="" width="18" height="18"></i>
                    <b>Expenses</b>
                    <p>Day-to-day running costs recorded against your own expense categories.</p>
                </article>

                <article class="home-feature home-reveal" data-delay="1">
                    <i><img src="{{ $icon('sales') }}" alt="" width="18" height="18"></i>
                    <b>Returns &amp; refunds</b>
                    <p>Take goods back, decide whether they re-enter stock, and record the customer refund separately.</p>
                </article>

                <article class="home-feature home-reveal" data-delay="2">
                    <i><img src="{{ $icon('dashboard') }}" alt="" width="18" height="18"></i>
                    <b>Reports</b>
                    <p>Ten operational reports across sales, collections, receivables, expenses, purchases, inventory, products, customers and staff.</p>
                </article>

                <article class="home-feature home-reveal" data-delay="3">
                    <i><img src="{{ $icon('staff') }}" alt="" width="18" height="18"></i>
                    <b>Staff permissions</b>
                    <p>Three roles, with account activation, locking and session control held by administrators.</p>
                </article>

                <article class="home-feature home-reveal">
                    <i><img src="{{ $icon('bell') }}" alt="" width="18" height="18"></i>
                    <b>Operational notifications</b>
                    <p>Alerts when stock runs low or a sale carries an outstanding balance, with a read and acknowledged state.</p>
                </article>

                <article class="home-feature home-reveal" data-delay="1">
                    <i><img src="{{ $icon('search') }}" alt="" width="18" height="18"></i>
                    <b>Audit history</b>
                    <p>An append-only record of business changes — who did what, to which record, and when.</p>
                </article>

                <article class="home-feature is-wide home-reveal" data-delay="2">
                    <i><img src="{{ $icon('whatsapp') }}" alt="" width="18" height="18"></i>
                    <b>WhatsApp receipts</b>
                    <p>Send a sale receipt to a customer who has opted in, with a delivery history you can check. Receipts only — Inventra sends no marketing messages.</p>
                </article>
            </div>
        </div>
    </section>
    --}}

    {{-- ════════════════════════════════════════════════════ roles ═══ --}}
    {{-- COMMENTED OUT: Access (roles) section — hidden on the homepage.
    <section class="home-roles" id="built-for-business" aria-labelledby="roles-title">
        <div class="home-shell">
            <div class="home-section-head home-reveal">
                <p class="home-eyebrow">Access</p>
                <h2 id="roles-title">One system. The right access for everyone.</h2>
                <p>People see the part of the business they are responsible for — no more, no less.</p>
            </div>

            <div class="home-role-grid">
                <article class="home-role home-role-admin home-reveal">
                    <span class="home-role-badge">Administrator</span>
                    <i><img src="{{ $icon('staff') }}" alt="" width="20" height="20"></i>
                    <h3>Full oversight</h3>
                    <p>Full business oversight, staff administration and operational control.</p>
                    <ul>
                        <li>Every module and report</li>
                        <li>Create staff and set roles</li>
                        <li>Lock, activate and sign out accounts</li>
                        <li>Audit trail and business settings</li>
                    </ul>
                </article>

                <article class="home-role home-role-manager home-reveal" data-delay="1">
                    <span class="home-role-badge">Manager</span>
                    <i><img src="{{ $icon('inventory') }}" alt="" width="20" height="20"></i>
                    <h3>Day-to-day operations</h3>
                    <p>Run day-to-day inventory, customer, sales and purchasing operations.</p>
                    <ul>
                        <li>Inventory, purchases and suppliers</li>
                        <li>Sales, payments, returns and refunds</li>
                        <li>Expenses and operational reports</li>
                        <li>No staff administration</li>
                    </ul>
                </article>

                <article class="home-role home-role-rep home-reveal" data-delay="2">
                    <span class="home-role-badge">Sales representative</span>
                    <i><img src="{{ $icon('sales') }}" alt="" width="20" height="20"></i>
                    <h3>Focused selling</h3>
                    <p>Record customers and sales through a focused, restricted workspace.</p>
                    <ul>
                        <li>Record sales and take payment</li>
                        <li>Register and look up customers</li>
                        <li>View products and stock</li>
                        <li>No reports, purchasing or staff access</li>
                    </ul>
                </article>
            </div>

            <p class="home-role-foot">Access is enforced by the application, not hidden in the interface.</p>
        </div>
    </section>
    --}}

    {{-- ═══════════════════════════════════════════════ how it works ═══ --}}
    {{-- COMMENTED OUT: How it works (steps) section — hidden on the homepage.
    <section class="home-steps-section" id="how-it-works" aria-labelledby="steps-title">
        <div class="home-shell">
            <div class="home-section-head home-reveal">
                <p class="home-eyebrow">How it works</p>
                <h2 id="steps-title">From setup to your first report</h2>
                <p>The same order a trading business already runs in.</p>
            </div>

            <div class="home-step-grid">
                @foreach ([
                    ['01', 'Set up products', 'Add your products with categories, prices and the reorder level that matters to you.'],
                    ['02', 'Receive stock', 'Record a purchase from a supplier and the quantities land in inventory.'],
                    ['03', 'Register customers', 'Capture the customers you sell to, once, with their contact details.'],
                    ['04', 'Record sales & payments', 'Sell, take what the customer paid, and let the balance be tracked for you.'],
                    ['05', 'Track operations', 'Returns, refunds, expenses and low-stock alerts as the days go on.'],
                    ['06', 'Review reports', 'See what you sold, collected, are owed and spent over any date range.'],
                ] as [$number, $title, $body])
                    <article class="home-step home-reveal" data-delay="{{ $loop->index % 3 }}">
                        <span>{{ $number }}</span>
                        <b>{{ $title }}</b>
                        <p>{{ $body }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
    --}}

    {{-- ══════════════════════════════════════ connected operations ═══ --}}
    <section class="home-flow-section" aria-labelledby="flow-title">
        <div class="home-shell">
            <div class="home-section-head home-reveal">
                <p class="home-eyebrow">Connected by design</p>
                <h2 id="flow-title">One sale, five things handled</h2>
                <p>Inventra is an integrated system, not a set of unrelated pages. Recording a sale once is enough.</p>
            </div>

            <div class="home-flow">
                @foreach ([
                    ['sales', 'Sale recorded', 'Customer, products and quantities captured once.'],
                    ['inventory', 'Stock updated', 'Quantities leave inventory and the movement is logged.'],
                    ['customers', 'Payment tracked', 'Paid in full, or a balance carried on the customer account.'],
                    ['whatsapp', 'Receipt generated', 'Printable, and sendable to customers who opted in.'],
                    ['dashboard', 'Management visibility', 'The sale appears in dashboards, reports and the audit trail.'],
                ] as [$glyph, $title, $body])
                    <div class="home-flow-item home-reveal" data-delay="{{ $loop->index % 3 }}">
                        <i><img src="{{ $icon($glyph) }}" alt="" width="22" height="22"></i>
                        <b>{{ $title }}</b>
                        <p>{{ $body }}</p>
                    </div>
                @endforeach
            </div>

            <div class="home-flow-note home-reveal">
                <img src="{{ $icon('search') }}" alt="" width="20" height="20">
                <p><b>Nothing is entered twice.</b> Because stock, customer balances, receipts and reports all read the same records, a correction in one place is a correction everywhere.</p>
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════ final CTA ═══ --}}
    {{-- COMMENTED OUT: Get started (final CTA) section — hidden on the homepage.
    <section class="home-cta" aria-labelledby="cta-title">
        <div class="home-shell">
            <div class="home-cta-inner home-reveal">
                <span class="home-cta-orb home-cta-orb-a" aria-hidden="true"></span>
                <span class="home-cta-orb home-cta-orb-b" aria-hidden="true"></span>
                <p class="home-eyebrow">Get started</p>
                <h2 id="cta-title">Your business shouldn't be difficult to understand.</h2>
                <p>Keep your stock, sales, customers and day-to-day operations in one place.</p>
                <div class="home-cta-actions">
                    <a href="{{ route('login') }}" class="home-btn home-btn-onblue">
                        Open Inventra
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h9M8.5 4.5 12 8l-3.5 3.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </div>
                <p class="home-cta-note">Administrators, managers and sales representatives sign in to the same workspace.</p>
            </div>
        </div>
    </section>
    --}}
</main>

{{-- ═══════════════════════════════════════════════════════════ footer ═══ --}}
<footer class="home-footer">
    <div class="home-shell">
        <div class="home-footer-grid">
            <div class="home-footer-brand">
                <span class="home-footer-lockup">
                    <span><img src="{{ asset('images/figma/auth-logo-mark.png') }}" alt=""></span>
                    <strong>inventra</strong>
                </span>
                <p>A business operations workspace for growing trading and retail businesses — inventory, sales, customers, purchasing, expenses and operational reports in one place.</p>
            </div>

            <div class="home-footer-col">
                <h2>Product</h2>
                <ul>
                    <li><a href="#features">Features</a></li>
                    {{-- COMMENTED OUT with their sections --}}
                    {{-- <li><a href="#how-it-works">How it works</a></li> --}}
                    {{-- <li><a href="#built-for-business">Built for business</a></li> --}}
                </ul>
            </div>

            <div class="home-footer-col">
                <h2>Access</h2>
                <ul>
                    <li><a href="{{ route('login') }}">Sign in</a></li>
                </ul>
            </div>
        </div>

        <div class="home-footer-base">
            <p>&copy; {{ now(config('business.timezone'))->year }} Inventra. All rights reserved.</p>
            <p>Operational records only. No profit, cost-of-goods or tax accounting.</p>
        </div>
    </div>
</footer>
@livewireScriptConfig
</body>
</html>
