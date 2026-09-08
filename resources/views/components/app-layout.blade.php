@props(['title', 'receipt' => false])
@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $management = in_array($user->role, [\App\Enums\UserRole::Admin, \App\Enums\UserRole::Manager], true);
    $groups = ['Workspace' => [
        ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
        ['inventory.index', 'inventory.*', $management ? 'Inventory' : 'Products', 'inventory'],
        ['customers.index', 'customers.*', 'Customers', 'customers'],
        ['sales.index', 'sales.*', 'Sales', 'sales'],
    ]];
    if ($management) {
        $groups['Operations'] = [
            ['returns.index', 'returns.*', 'Returns', 'sales'],
            ['refunds.index', 'refunds.*', 'Refunds', 'sales'],
            ['sale-payments.index', 'sale-payments.*', 'Payments', 'sales'],
            ['suppliers.index', 'suppliers.*', 'Suppliers', 'customers'],
            ['purchases.index', 'purchases.*', 'Purchases', 'inventory'],
            ['expenses.index', 'expenses.*', 'Expenses', 'sales'],
            ['expense-categories.index', 'expense-categories.*', 'Expense categories', 'inventory'],
            ['reports.index', 'reports.*', 'Reports', 'dashboard'],
            ['notifications.index', 'notifications.*', 'Notifications', 'bell'],
            ['whatsapp.deliveries.index', 'whatsapp.*', 'WhatsApp history', 'whatsapp'],
        ];
    }
    if ($user->role === \App\Enums\UserRole::Admin) {
        $groups['Administration'] = [
            ['staff.index', 'staff.*', 'Staff', 'staff'],
            ['audit.index', 'audit.*', 'Audit Trail', 'dashboard'],
            ['settings.business.edit', 'settings.*', 'Business Settings', 'inventory'],
        ];
    }
    $unreadAlerts = app(\App\Alerts\UnreadAlertCount::class)->badge($user);
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
        <div class="inventra-brand-row"><a href="{{ route('dashboard') }}" class="inventra-logo"><img src="{{ asset('images/figma/inventra-logo.png') }}" alt="Inventra"></a><button type="button" class="inventra-menu-close" x-on:click="closeMenu" aria-label="Close navigation">×</button></div>
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
        <div class="inventra-user-card"><span class="inventra-avatar">{{ $initials }}</span><span class="min-w-0 flex-1"><strong title="{{ $user->name }}">{{ $user->name }}</strong><small>{{ $user->role->label() }}</small></span><form method="POST" action="{{ route('logout') }}">@csrf<button class="inventra-logout" aria-label="Sign out" title="Sign out"><img src="{{ asset('images/figma/icon-logout.svg') }}" alt=""></button></form></div>
    </aside>
    <div class="inventra-workspace" x-bind:inert="compact && menuOpen">
        <header class="inventra-topbar">
            <div class="inventra-topbar-title"><button id="navigation-toggle" type="button" class="inventra-menu-trigger" aria-controls="app-navigation" x-bind:aria-expanded="menuOpen" x-on:click="openMenu">Menu</button><span class="inventra-breadcrumb">Workspace <span aria-hidden="true">/</span></span><h1>{{ $title }}</h1></div>
            <div class="inventra-top-actions">
                @if($management)<a class="inventra-icon-button" href="{{ route('notifications.index') }}" aria-label="{{ $unreadAlerts === '' ? 'Notifications' : 'Notifications, '.$unreadAlerts.' unread' }}"><img src="{{ asset('images/figma/icon-bell.svg') }}" alt="">@if($unreadAlerts !== '')<span class="inventra-topbar-badge">{{ $unreadAlerts }}</span>@endif</a>@endif
                @can('create', \App\Models\Sale::class)<a href="{{ route('sales.create') }}" class="inventra-primary-action"><img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Record sale</a>@endcan
            </div>
        </header>
        <main id="main-content" tabindex="-1" class="inventra-content">@if(session('status'))<div role="status" class="inventra-alert-success">{{ session('status') }}</div>@endif{{ $slot }}</main>
    </div>
    @livewireScriptConfig
</body>
</html>
