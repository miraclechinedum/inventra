{{--
    The customer list.

    A compact toolbar over a table of people. Every figure on the row — what a customer has spent,
    when they last bought — is computed by the controller as a subquery, so ten rows cost one query.

    The WhatsApp column describes the *number*, never consent: "Same" means WhatsApp reaches this
    customer on their ordinary phone, not that they agreed to be messaged. Consent lives on the
    profile and is the only thing that decides whether anything is sent.

    Every class here is `cust-` prefixed and scoped under `.cust`, so nothing reaches the Sales,
    Inventory or Reports tables.
--}}
<x-app-layout title="Customers">
    <div class="cust">
        {{-- ── Toolbar ───────────────────────────────────────────────────────────────────────── --}}
        <form method="GET" class="cust-toolbar">
            <div class="cust-toolbar-filters">
                <select name="period" aria-label="Registered" class="cust-select" data-table-control>
                    <option value="all" @selected($filters['period'] === 'all')>All time</option>
                    <option value="30" @selected($filters['period'] === '30')>Last 30 days</option>
                    <option value="90" @selected($filters['period'] === '90')>Last 90 days</option>
                    <option value="365" @selected($filters['period'] === '365')>Last 12 months</option>
                </select>

                <select name="whatsapp" aria-label="WhatsApp consent" class="cust-select" data-table-control>
                    <option value="" @selected($filters['whatsapp'] === '')>All customers</option>
                    <option value="opted_in" @selected($filters['whatsapp'] === 'opted_in')>WhatsApp opted-in</option>
                    <option value="opted_out" @selected($filters['whatsapp'] === 'opted_out')>Not opted-in</option>
                </select>

                @if($canManageStatus)
                    <select name="status" aria-label="Status" class="cust-select" data-table-control>
                        <option value="" @selected($filters['status'] === '')>Active &amp; archived</option>
                        <option value="active" @selected($filters['status'] === 'active')>Active only</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>Archived only</option>
                    </select>
                @endif
            </div>

            <div class="cust-toolbar-actions">
                {{-- One control: the label draws the border and the field sits inside it sharing
                     its background, so the icon reads as part of the input rather than as a box
                     bolted to one. The label is the accessible name; the placeholder is not. --}}
                <label class="cust-search">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                    <span class="ui-visually-hidden-until-focus">Search customers</span>
                    <input type="search" name="search" class="cust-search-input" value="{{ $filters['search'] }}"
                           placeholder="Search customers..." data-table-control>
                </label>

                <select name="sort" aria-label="Sort" class="cust-select cust-sort" data-table-control>
                    <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                    <option value="name" @selected($filters['sort'] === 'name')>Name A–Z</option>
                    <option value="spent" @selected($filters['sort'] === 'spent')>Highest spend</option>
                </select>

                {{-- Filters apply as they change through the shared handler; the submit stays in the
                     markup so the toolbar still works with JavaScript unavailable. --}}
                <button type="submit" class="ui-visually-hidden-until-focus">Apply</button>

                @if($customers->total() > 0)
                    <a href="{{ route('customers.export', request()->query()) }}" class="cust-button">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                        Export
                    </a>
                @else
                    {{-- Nothing to export, so the control says so rather than producing an empty file. --}}
                    <span class="cust-button is-disabled" aria-disabled="true">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                        Export
                    </span>
                @endif
            </div>
        </form>

        <div class="cust-card">
            @if($customers->total() > 0)
                <div class="cust-table-scroll">
                    <table class="cust-table">
                        <caption class="ui-visually-hidden-until-focus">Customers. Select a row to open the profile.</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="cust-sn">S/N</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Phone</th>
                                <th scope="col">WhatsApp</th>
                                <th scope="col">Email</th>
                                <th scope="col">Tag</th>
                                <th scope="col" class="cust-numeric">Total spent</th>
                                <th scope="col">Last buy</th>
                                <th scope="col"><span class="ui-visually-hidden-until-focus">Open</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $index => $customer)
                                <tr class="cust-row">
                                    {{-- Presentation only, and continuous across pages: the
                                         paginator's offset means page two starts at 11, not 1.
                                         Never the customer's database id. --}}
                                    <td class="cust-sn" data-label="S/N">{{ $customers->firstItem() + $index }}</td>
                                    <td data-label="Customer">
                                        <span class="cust-person">
                                            <x-customer-avatar :customer="$customer" size="sm" />
                                            {{-- The whole row is navigable, but the link is what
                                                 carries it: one tab stop, one accessible name, and
                                                 it still works without JavaScript. --}}
                                            <a href="{{ route('customers.show', $customer) }}" class="cust-name">
                                                {{ $customer->full_name }}
                                            </a>
                                        </span>
                                    </td>
                                    <td data-label="Phone" class="cust-phone">{{ $customer->phone }}</td>
                                    <td data-label="WhatsApp">
                                        {{-- The number relationship, not consent. --}}
                                        @if($customer->effectiveWhatsAppPhone() === null)
                                            <span class="cust-muted">&mdash;</span>
                                        @elseif($customer->whatsAppUsesPhone())
                                            <span class="cust-same">
                                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                                                     stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
                                                Same
                                            </span>
                                        @else
                                            <span class="cust-phone">{{ $customer->whatsapp_phone }}</span>
                                        @endif
                                    </td>
                                    <td data-label="Email">
                                        {{ $customer->email ?: '' }}
                                        @unless($customer->email)<span class="cust-muted">&mdash;</span>@endunless
                                    </td>
                                    <td data-label="Tag">
                                        {{ $customer->tag ?: '' }}
                                        @unless($customer->tag)<span class="cust-muted">&mdash;</span>@endunless
                                    </td>
                                    <td data-label="Total spent" class="cust-numeric cust-spent">
                                        &#8358;{{ \App\Support\Money::compact((string) $customer->total_spent) }}
                                    </td>
                                    <td data-label="Last buy" class="cust-muted">
                                        @if($customer->last_sale_date)
                                            {{ \Carbon\CarbonImmutable::parse($customer->last_sale_date)->format('j M') }}
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    <td class="cust-chevron">
                                        <a href="{{ route('customers.show', $customer) }}" tabindex="-1" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-table-footer :paginator="$customers" noun="customer" />
            @elseif($totalCustomers > 0)
                {{-- Customers exist; none match. Different from having none at all. --}}
                <div class="cust-empty">
                    <span class="cust-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                    </span>
                    <h2>No customers match</h2>
                    <p>Try another search, or clear the filters to see all {{ number_format($totalCustomers) }}.</p>
                    <a href="{{ route('customers.index') }}" class="cust-button">Clear filters</a>
                </div>
            @else
                {{-- Genuinely none. The count is real, never a placeholder. --}}
                <div class="cust-empty">
                    <span class="cust-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </span>
                    <h2>No customers yet</h2>
                    <p>They'll appear here when you add them or record their first sale.</p>
                    @can('create', \App\Models\Customer::class)
                        <a href="{{ route('customers.create') }}" class="inventra-primary-action">
                            <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Add Customer
                        </a>
                    @endcan
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
