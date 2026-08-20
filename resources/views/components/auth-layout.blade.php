@props(['title', 'eyebrow' => 'Inventra Smart Trade'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased">
        <main class="grid min-h-screen lg:grid-cols-2">
            <section class="relative hidden overflow-hidden bg-[#0b3b8f] p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <div class="absolute -right-32 -top-32 size-96 rounded-full bg-blue-400/20"></div>
                <div class="absolute -bottom-48 -left-24 size-[30rem] rounded-full bg-cyan-300/15"></div>
                <a href="{{ route('login') }}" class="relative text-xl font-bold tracking-tight">Inventra</a>
                <div class="relative max-w-lg">
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-blue-200">Smart trade operations</p>
                    <h1 class="mt-5 text-5xl font-semibold leading-tight">Secure access to the tools that keep business moving.</h1>
                    <p class="mt-6 text-lg leading-8 text-blue-100">A focused workspace for trusted staff, protected by role-aware access and secure account controls.</p>
                </div>
                <p class="relative text-sm text-blue-200">Internal business system</p>
            </section>

            <section class="flex items-center justify-center px-6 py-12 sm:px-12">
                <div class="w-full max-w-md">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-[#0b56c9]">{{ $eyebrow }}</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight">{{ $title }}</h2>
                    <div class="mt-8">{{ $slot }}</div>
                </div>
            </section>
        </main>
        @livewireScriptConfig
    </body>
</html>
