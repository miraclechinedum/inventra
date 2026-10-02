{{--
    One centred error state, in whichever shell the visitor is entitled to.

    A signed-in staff member gets the application shell, so their navigation stays available. An
    unauthenticated visitor gets a bare page: the shell carries the navigation and the account card
    — a real name and role — so rendering it for a signed-out visitor turns a 404 into a disclosure
    of who uses this installation.

    Included with `$title` and `$state`, the name of the partial holding the state's own markup.
--}}
@php($shellUser = auth()->hasUser() ? auth()->user() : null)
@if ($shellUser !== null && $shellUser->exists && $shellUser->getAttribute('name') !== null)
    <x-app-layout :title="$title">
        <div class="err-state">@include($state)</div>
    </x-app-layout>
@else
    <!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} · {{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="err-page"><main class="err-state">@include($state)</main></body>
    </html>
@endif
