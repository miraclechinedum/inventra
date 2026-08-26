@props(['title'])
@php
    $user = auth()->user();
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['route' => 'inventory.index', 'match' => 'inventory.*', 'label' => $user->role === \App\Enums\UserRole::SalesRep ? 'Products' : 'Inventory', 'icon' => 'inventory'],
        ['route' => 'sales.index', 'match' => 'sales.*', 'label' => 'Sales', 'icon' => 'sales'],
        ['route' => 'customers.index', 'match' => 'customers.*', 'label' => 'Customers', 'icon' => 'customers'],
    ];
    if ($user->role === \App\Enums\UserRole::Admin) $nav[] = ['route' => 'staff.index', 'match' => 'staff.*', 'label' => 'Staff', 'icon' => 'staff'];
@endphp
<!DOCTYPE html><html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title }} · {{ config('app.name') }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="inventra-app">
<aside class="inventra-sidebar"><a href="{{ route('dashboard') }}" class="inventra-logo"><img src="{{ asset('images/figma/inventra-logo.png') }}" alt="Inventra"></a><nav class="inventra-nav" aria-label="Primary navigation">@foreach ($nav as $item)<a href="{{ route($item['route']) }}" @class(['inventra-nav-item', 'is-active' => request()->routeIs($item['match'])])><img src="{{ asset('images/figma/icon-'.$item['icon'].'.svg') }}" alt=""><span>{{ $item['label'] }}</span></a>@endforeach<span class="inventra-nav-item is-disabled"><span class="inventra-nav-placeholder">▥</span><span>Reports</span></span><span class="inventra-nav-item is-disabled"><img src="{{ asset('images/figma/icon-whatsapp.svg') }}" alt=""><span>WhatsApp Automation</span></span></nav><div class="inventra-user-card"><span class="inventra-avatar">{{ $initials }}</span><span class="min-w-0 flex-1"><strong>{{ $user->name }}</strong><small>{{ $user->role->label() }}</small></span><form method="POST" action="{{ route('logout') }}">@csrf<button class="inventra-logout" title="Sign out"><img src="{{ asset('images/figma/icon-logout.svg') }}" alt="Sign out"></button></form></div></aside>
<div class="inventra-workspace"><header class="inventra-topbar"><h1>{{ $title }}</h1><div class="inventra-top-actions"><span class="inventra-global-search"><img src="{{ asset('images/figma/icon-search.svg') }}" alt=""><span>Search...</span></span><button class="inventra-icon-button" disabled aria-label="Notifications"><img src="{{ asset('images/figma/icon-bell.svg') }}" alt=""></button><a href="{{ route('sales.create') }}" class="inventra-primary-action"><img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Record Sale</a></div></header><main class="inventra-content">@if (session('status'))<div class="inventra-alert-success">{{ session('status') }}</div>@endif{{ $slot }}</main></div>
@livewireScriptConfig</body></html>
