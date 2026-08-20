<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
        <main class="grid min-h-screen place-items-center px-6">
            <section class="max-w-2xl text-center" x-data="{ ready: true }">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-emerald-400">Foundation ready</p>
                <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-6xl">Inventra Smart Trade</h1>
                <p class="mt-6 text-lg leading-8 text-slate-300">
                    The secure application foundation is installed. Business modules will be added in the next phase.
                </p>
                <p class="mt-8 text-sm text-slate-500" x-show="ready" x-cloak>
                    Laravel · Livewire · Alpine.js · Tailwind CSS
                </p>
            </section>
        </main>
        @livewireScriptConfig
    </body>
</html>
