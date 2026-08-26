<x-auth-layout title="Welcome back 👋" subtitle="Sign in to Akin Auto Parts">
    @if (session('status'))<div class="inventra-login-status">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('login.store') }}" class="inventra-login-form" x-data="{ submitting: false, reveal: false }" x-on:submit="submitting = true">
        @csrf
        <label class="inventra-login-field"><span>Email or phone</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-mail.svg') }}" alt=""><input name="identifier" value="{{ old('identifier') }}" placeholder="you@business.com" autocomplete="username" required autofocus></span>@error('identifier')<small>{{ $message }}</small>@enderror</label>
        <label class="inventra-login-field"><span>Password</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-lock.svg') }}" alt=""><input name="password" x-bind:type="reveal ? 'text' : 'password'" placeholder="••••••••••" autocomplete="current-password" required><button type="button" x-on:click="reveal = ! reveal" aria-label="Show or hide password"><img src="{{ asset('images/figma/auth-eye.svg') }}" alt=""></button></span>@error('password')<small>{{ $message }}</small>@enderror</label>
        <a href="{{ route('password.request') }}" class="inventra-forgot-link">Forgot password?</a>
        <button type="submit" class="inventra-sign-in" x-bind:disabled="submitting"><span x-show="! submitting">Sign in</span><span x-cloak x-show="submitting">Signing in…</span></button>
    </form>
    <div class="inventra-auth-divider"><span>or</span></div>
    <button type="button" class="inventra-pin-unlock" disabled><img src="{{ asset('images/figma/auth-fingerprint.svg') }}" alt="">Unlock with PIN</button>
</x-auth-layout>
