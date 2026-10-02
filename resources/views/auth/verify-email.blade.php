<x-auth-layout title="Verify your email" subtitle="Open the link we sent to start using Inventra.">
    @if (session('status'))<div class="inventra-login-status" role="status">{{ session('status') }}</div>@endif

    <p class="inventra-auth-subtitle">We sent a verification link to:</p>
    <p class="inventra-verify-address">{{ $email }}</p>

    <form method="POST" action="{{ route('verification.send') }}" class="inventra-login-form" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <p class="inventra-auth-switch">Didn't receive it?</p>
        <button type="submit" class="inventra-sign-in" x-bind:disabled="submitting">Resend verification email</button>
    </form>

    {{-- A mistyped address can be corrected here, with the account's current password. The
         Business and everything else stay as they are. --}}
    <div x-data="{ open: {{ $errors->hasAny(['email', 'current_password']) ? 'true' : 'false' }} }" class="inventra-verify-correct">
        <p class="inventra-auth-switch">Wrong email? <button type="button" class="inventra-forgot-link" x-on:click="open = ! open" x-bind:aria-expanded="open">Change email address</button></p>

        <form method="POST" action="{{ route('verification.email.update') }}" class="inventra-login-form" x-cloak x-show="open" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            @method('PUT')
            <label class="inventra-login-field"><span>New email address</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-mail.svg') }}" alt=""><input type="email" name="email" value="{{ \App\Support\OldInput::scalar('email') }}" autocomplete="email" maxlength="255" required></span>@error('email')<small>{{ $message }}</small>@enderror</label>
            <label class="inventra-login-field"><span>Current password</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-lock.svg') }}" alt=""><input type="password" name="current_password" autocomplete="current-password" required></span>@error('current_password')<small>{{ $message }}</small>@enderror</label>
            <button type="submit" class="inventra-sign-in" x-bind:disabled="submitting">Update email and send a new link</button>
        </form>
    </div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <p class="inventra-auth-switch"><button type="submit" class="inventra-forgot-link">Sign out</button></p>
    </form>
</x-auth-layout>
