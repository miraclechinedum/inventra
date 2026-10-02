@props(['title', 'receipt' => false])
@php
    $user = auth()->user();
    $user = $user?->exists ? ($user->fresh() ?? $user) : $user;
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $management = in_array($user->role, [\App\Enums\UserRole::Admin, \App\Enums\UserRole::Manager], true);
    $groups = ['Workspace' => [
        ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
        ['inventory.index', ['inventory.index', 'inventory.products.*'], $management ? 'Inventory' : 'Products', 'inventory'],
        ['customers.index', 'customers.*', 'Customers', 'customers'],
        ['sales.index', 'sales.*', 'Sales', 'sales'],
    ]];
    if ($management) {
        $groups['Operations'] = [
            ...($user->can('viewAny', \App\Models\ProductCategory::class)
                ? [['inventory.categories.index', 'inventory.categories.*', 'Product categories', 'inventory']]
                : []),
            ['returns.index', 'returns.*', 'Returns', 'sales'],
            ['refunds.index', 'refunds.*', 'Refunds', 'sales'],
            ['sale-payments.index', 'sale-payments.*', 'Payments', 'sales'],
            ['suppliers.index', 'suppliers.*', 'Suppliers', 'customers'],
            ['purchases.index', 'purchases.*', 'Purchases', 'inventory'],
            ['expenses.index', 'expenses.*', 'Expenses', 'sales'],
            ['expense-categories.index', 'expense-categories.*', 'Expense categories', 'inventory'],
            ['reports.index', 'reports.*', 'Reports', 'dashboard'],
            ['notifications.index', 'notifications.*', 'Notifications', 'bell'],
            ['discounts.index', 'discounts.*', 'Discount approvals', 'sales'],
            ['whatsapp.logs.index', 'whatsapp.logs.*', 'WhatsApp Logs', 'whatsapp'],
        ];
    }
    if ($user->role === \App\Enums\UserRole::Admin) {
        $groups['Administration'] = [
            ['staff.index', 'staff.*', 'Staff', 'staff'],
            // Matched on the automation routes alone: `whatsapp.*` would also claim the log
            // entry above and light both at once.
            ['whatsapp.automation.index', 'whatsapp.automation.*', 'WhatsApp Automation', 'whatsapp'],
            ['audit.index', 'audit.*', 'Audit Trail', 'dashboard'],
            ['settings.business.edit', 'settings.business.*', 'Business Settings', 'inventory'],
            ['subscription.show', 'subscription.*', 'Subscription', 'dashboard'],
        ];
    }
    // An error page for an unmatched URL renders here without passing the route's business
    // middleware, so no Business is resolved. Alert chrome is then left empty rather than inferred.
    $inBusiness = app(\App\Tenancy\CurrentBusiness::class)->has();
    // Commercial access, for the banner below. Only ever this Business's own subscription.
    $commercialAccess = $inBusiness ? app(\App\Subscriptions\SubscriptionAccess::class)->for(app(\App\Tenancy\CurrentBusiness::class)->get()) : null;
    $unreadAlerts = $inBusiness ? app(\App\Alerts\UnreadAlertCount::class)->badge($user) : '';
    // The dropdown preview. Scoped to this operator's own rows and to the alert types their role
    // may read, by the same AlertInbox query the full notifications page uses — so the preview can
    // never surface something the page itself would refuse. Only fetched for roles that see the
    // bell at all, so no extra query is spent on a Sales Representative.
    $alertPreview = $management && $inBusiness
        ? app(\App\Alerts\AlertInbox::class)->preview($user)
        : collect();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['inventra-app', 'inventra-receipt' => $receipt]) x-data="navigation" x-bind:class="menuOpen ? 'navigation-open' : ''" x-on:keydown.escape.window="closeMenu" x-on:keydown.tab="trapFocus">
    <a href="#main-content" class="inventra-skip-link">Skip to content</a>
    <button type="button" class="inventra-nav-backdrop" x-cloak x-show="menuOpen" x-on:click="closeMenu" aria-label="Close navigation" tabindex="-1"></button>
    <aside id="app-navigation" class="inventra-sidebar" x-bind:inert="compact && !menuOpen" x-bind:role="compact ? 'dialog' : null" x-bind:aria-modal="compact && menuOpen ? 'true' : null" aria-label="Application navigation">
        <div class="inventra-brand-row"><a href="{{ route('dashboard') }}" class="inventra-logo"><img src="{{ asset('images/figma/inventra-logo.png') }}" alt="Inventra"></a><button type="button" class="inventra-menu-close" x-on:click="closeMenu" aria-label="Close navigation" data-tooltip="Close navigation">×</button></div>
        <nav class="inventra-nav" aria-label="Primary navigation">
            @foreach($groups as $group => $items)
                <div class="inventra-nav-group"><p>{{ $group }}</p>
                    @foreach($items as [$route, $match, $label, $icon])
                        <a href="{{ route($route) }}" @class(['inventra-nav-item', 'is-active' => request()->routeIs($match)]) @if(request()->routeIs($match)) aria-current="page" @endif title="{{ $label }}">
                            <img src="{{ asset('images/figma/icon-'.$icon.'.svg') }}" alt=""><span>{{ $label }}</span>
                            @if($route === 'notifications.index' && $unreadAlerts !== '')<span class="inventra-nav-badge">{{ $unreadAlerts }}</span>@endif
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        <div class="inventra-user-card"><a href="{{ route('profile.edit') }}" class="inventra-avatar" aria-label="My profile" data-tooltip="My profile">@if($user->photo_path)<img src="{{ route('users.photo', $user) }}" alt="" width="36" height="36" class="h-full w-full rounded-full object-cover">@else{{ $initials }}@endif</a><span class="min-w-0 flex-1"><strong title="{{ $user->name }}">{{ $user->name }}</strong><small>{{ $user->role->label() }}</small></span><button type="button" class="inventra-logout" x-data="logoutTrigger" x-on:click="ask" aria-label="Sign out" data-tooltip="Sign out"><img src="{{ asset('images/figma/icon-logout.svg') }}" alt=""></button></div>
    </aside>
    <div class="inventra-workspace" x-bind:inert="compact && menuOpen">
        <header class="inventra-topbar">
            <div class="inventra-topbar-title"><button id="navigation-toggle" type="button" class="inventra-menu-trigger" aria-controls="app-navigation" x-bind:aria-expanded="menuOpen" x-on:click="openMenu">Menu</button><span class="inventra-breadcrumb">Workspace <span aria-hidden="true">/</span></span><h1>{{ $title }}</h1></div>
            <div class="inventra-top-actions">
                {{-- Navbar type-ahead. Results come from /search, which gates customers and products
                     by their own policies, so this box discloses nothing the list pages would not. --}}
                <div class="topbar-search" x-data="globalSearch" x-on:keydown.escape.window="close" x-on:click.outside="close">
                    <label class="dash-sr-only" for="topbar-search-input">Search customers and products</label>
                    <span class="topbar-search-field">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input id="topbar-search-input" type="search" placeholder="Search..." autocomplete="off"
                               x-ref="input" x-model="query" x-on:input="schedule" x-on:focus="schedule"
                               x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
                               x-on:keydown.enter.prevent="choose"
                               role="combobox" aria-autocomplete="list" aria-controls="topbar-search-panel"
                               x-bind:aria-expanded="open ? 'true' : 'false'"
                               data-search-url="{{ route('search') }}"
                               data-customer-create-url="{{ \Illuminate\Support\Facades\Route::has('customers.create') ? route('customers.create') : '' }}">
                    </span>

                    <div class="topbar-search-panel" id="topbar-search-panel" role="listbox" x-cloak x-show="open">
                        <template x-if="loading">
                            <p class="topbar-search-note">Searching…</p>
                        </template>

                        <template x-if="!loading && hasResults">
                            <div>
                                <template x-for="(item, index) in flat" :key="item.key">
                                    <a x-bind:href="item.url" role="option"
                                       x-bind:class="{ 'topbar-result': true, 'is-active': index === active }"
                                       x-bind:aria-selected="index === active ? 'true' : 'false'"
                                       x-on:mouseenter="active = index" x-on:click="close">
                                        <template x-if="item.type === 'customer'">
                                            <span class="topbar-result-avatar" x-text="item.initials" aria-hidden="true"></span>
                                        </template>
                                        <span class="topbar-result-body">
                                            <strong x-text="item.name"></strong>
                                            <small x-text="item.meta"></small>
                                        </span>
                                        <template x-if="item.type === 'product'">
                                            <span x-bind:class="item.inStock ? 'topbar-result-stock is-in' : 'topbar-result-stock is-out'"
                                                  x-text="item.stockLabel"></span>
                                        </template>
                                    </a>
                                </template>

                                {{-- Offered only when the query found no customer AND no product: a
                                     search that surfaced products is a catalogue lookup, not a
                                     half-finished customer record. --}}
                                <template x-if="canCreateCustomer && createUrl && !hasResults">
                                    <a x-bind:href="createUrl" class="topbar-result is-create" x-on:click="close">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                                        <span x-text="'Add &quot;' + query + '&quot; as new customer'"></span>
                                    </a>
                                </template>
                            </div>
                        </template>

                        <template x-if="!loading && !hasResults && query.length >= 2">
                            <div class="topbar-search-empty">
                                <span class="topbar-search-empty-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5M9 9l4 4M13 9l-4 4"/></svg>
                                </span>
                                <strong x-text="emptyTitle"></strong>
                                <p x-text="emptyHint"></p>
                                <div class="topbar-search-empty-actions">
                                    <button type="button" class="dash-button" x-on:click="clear">Clear search</button>
                                    <template x-if="canCreateCustomer && createUrl">
                                        <a x-bind:href="createUrl" class="dash-button is-primary" x-on:click="close"
                                           x-text="'Add &quot;' + query + '&quot; as new customer'"></a>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                @if($management)
                    <div class="topbar-notifications" x-data="notificationMenu" x-on:keydown.escape.window="close" x-on:click.outside="close">
                        <button type="button" class="inventra-icon-button" x-ref="trigger" x-on:click="toggle"
                                aria-haspopup="menu" aria-controls="topbar-notification-panel"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                aria-label="{{ $unreadAlerts === '' ? 'Notifications' : 'Notifications, '.$unreadAlerts.' unread' }}">
                            <img src="{{ asset('images/figma/icon-bell.svg') }}" alt="">
                            @if($unreadAlerts !== '')<span class="inventra-topbar-badge">{{ $unreadAlerts }}</span>@endif
                        </button>

                        <div class="topbar-notification-panel" id="topbar-notification-panel" x-cloak x-show="open">
                            <header class="topbar-notification-head">
                                <strong>Notifications</strong>
                                @if($unreadAlerts !== '')
                                    {{-- The real mark-all-read action, CSRF-protected, not a visual fake. --}}
                                    <form method="POST" action="{{ route('notifications.read-all') }}">
                                        @csrf
                                        <button type="submit" class="dash-link">Mark all read</button>
                                    </form>
                                @endif
                            </header>

                            @forelse($alertPreview as $notification)
                                @php($alert = $notification->alert)
                                <a href="{{ route('notifications.show', $notification) }}" @class(['topbar-notification', 'is-unread' => ! $notification->isRead()])>
                                    <span @class(['topbar-notification-icon', 'is-'.$alert->severity->value]) aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                                    </span>
                                    <span class="topbar-notification-body">
                                        <strong>{{ $alert->title }}</strong>
                                        <small>{{ $alert->first_detected_at?->diffForHumans() }}</small>
                                    </span>
                                    @unless($notification->isRead())<span class="topbar-notification-dot" aria-label="Unread"></span>@endunless
                                </a>
                            @empty
                                <p class="topbar-search-note">You have no notifications.</p>
                            @endforelse

                            <a href="{{ route('notifications.index') }}" class="topbar-notification-all">View all notifications</a>
                        </div>
                    </div>
                @endif
                {{-- The Sales index carries its own Record Sale action beside the list it belongs to, so the
                     shell's copy is suppressed there rather than showing the same primary action twice. --}}
                @can('create', \App\Models\Sale::class)@unless(request()->routeIs('sales.index'))<a href="{{ route('sales.create') }}" class="inventra-primary-action"><img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Record sale</a>@endunless@endcan
            </div>
        </header>
        <main id="main-content" tabindex="-1" class="inventra-content">
        @if ($commercialAccess === \App\Subscriptions\Access::Restricted)
            <p class="inventra-subscription-banner is-restricted" role="status">{{ \App\Http\Middleware\EnsureSubscriptionPermitsWrites::MESSAGE }} @if ($user->role === \App\Enums\UserRole::Admin)<a href="{{ route('subscription.show') }}">View subscription</a>@endif</p>
        @elseif ($commercialAccess === \App\Subscriptions\Access::Grace)
            <p class="inventra-subscription-banner" role="status">Your trial or billing period has ended. Inventra keeps working for a few more days. @if ($user->role === \App\Enums\UserRole::Admin)<a href="{{ route('subscription.show') }}">View subscription</a>@endif</p>
        @endif
@php($flash = collect(['status', 'saleRecorded', 'saleCorrected'])->map(fn ($key) => session($key))->filter()->first())
        @if($flash)<div role="status" class="inventra-alert-success" x-data="autoDismiss" x-show="shown" x-cloak><svg class="inventra-alert-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>{{ $flash }}</span><button type="button" class="inventra-alert-dismiss" x-on:click="hide" aria-label="Dismiss message" data-tooltip="Dismiss">&times;</button></div>@endif{{ $slot }}@include('components._logout-modal')
        </main>
    </div>
    @livewireScriptConfig
</body>
</html>
