@props(['title'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-950 antialiased">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-6">
                <div class="flex items-center gap-8">
                    <a href="{{ route('dashboard') }}" class="font-bold text-[#0b56c9]">Inventra</a>
                    @if (auth()->user()->role === \App\Enums\UserRole::Admin)
                        <nav class="flex items-center gap-1 text-sm font-semibold text-slate-600">
                            <a href="{{ route('dashboard') }}" class="rounded-lg px-3 py-2 hover:bg-slate-100">Dashboard</a>
                            <a href="{{ route('staff.index') }}" class="rounded-lg px-3 py-2 hover:bg-slate-100">Staff</a>
                        </nav>
                    @endif
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-sm font-semibold">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-slate-500">{{ auth()->user()->role->label() }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">Sign out</button>
                    </form>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-5 py-8 sm:px-6">
            @if (session('status'))
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
            @endif
            {{ $slot }}
        </main>
        @livewireScriptConfig
    </body>
</html>
