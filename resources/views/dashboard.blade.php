<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Dashboard · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-950 antialiased">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <div>
                    <p class="font-bold text-[#0b56c9]">Inventra</p>
                    <p class="text-xs text-slate-500">{{ $user->role->label() }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Sign out</button>
                </form>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-6 py-16">
            <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#0b56c9]">Access ready</p>
                <h1 class="mt-3 text-3xl font-bold">Welcome, {{ $user->name }}</h1>
                <p class="mt-4 max-w-2xl text-slate-600">Your {{ strtolower($user->role->label()) }} account is authenticated. Business dashboards will be introduced in a later phase.</p>
                @if ($user->role === \App\Enums\UserRole::Admin)
                    <a href="{{ route('staff.index') }}" class="mt-6 inline-flex rounded-xl bg-[#0b56c9] px-5 py-3 font-semibold text-white">Manage staff accounts</a>
                @endif
            </div>
        </main>
        @livewireScriptConfig
    </body>
</html>
