@props(['title', 'eyebrow' => null, 'subtitle' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title }} · {{ config('app.name') }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="inventra-auth-page"><main class="inventra-auth-shell">
<section class="inventra-auth-brand"><span class="inventra-auth-orb inventra-auth-orb-top"></span><span class="inventra-auth-orb inventra-auth-orb-bottom"></span>
    <div class="inventra-auth-logo"><span><img src="{{ asset('images/figma/auth-logo-mark.png') }}" alt=""></span><strong>inventra</strong></div>
    <div class="inventra-auth-copy"><h1>Inventory, sales &amp; customers —<br>in one place.</h1><p>Built for modern traders. Track stock in real time,<br>record a sale in under a minute, and keep customers<br>close.</p><ul><li><span><img src="{{ asset('images/figma/auth-check.svg') }}" alt=""></span>Live stock levels &amp; low-stock alerts</li><li><span><img src="{{ asset('images/figma/auth-check.svg') }}" alt=""></span>One-minute sales recording</li><li><span><img src="{{ asset('images/figma/auth-check.svg') }}" alt=""></span>Automated WhatsApp follow-ups</li></ul></div>
    <div class="inventra-sales-card"><span>Sales today <b>↗ 12%</b></span><strong>₦186,400</strong><i><b></b><b></b><b></b><b></b><b></b><b></b></i></div>
</section>
<section class="inventra-auth-form"><div class="inventra-auth-form-inner">@if($eyebrow)<p class="inventra-auth-eyebrow">{{ $eyebrow }}</p>@endif<h2>{{ $title }}</h2>@if($subtitle)<p class="inventra-auth-subtitle">{{ $subtitle }}</p>@endif<div class="inventra-auth-slot">{{ $slot }}</div></div></section>
</main>@livewireScriptConfig</body></html>
