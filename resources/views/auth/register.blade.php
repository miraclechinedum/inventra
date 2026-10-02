<x-auth-layout title="Create your Inventra account" subtitle="Set up your business in under a minute. You can add everything else later.">
    {{-- Identity only. The Business, the owner's Administrator role and every default are assigned
         by the server; nothing here can choose them. --}}
    <form method="POST" action="{{ route('register.store') }}" class="inventra-login-form inventra-register-form" x-data="{ submitting: false, reveal: false }" x-on:submit="submitting = true" novalidate>
        @csrf

        <p class="inventra-auth-eyebrow">Your details</p>
        <label class="inventra-login-field"><span>Full name</span><span class="inventra-login-control"><input name="owner_name" value="{{ \App\Support\OldInput::scalar('owner_name') }}" placeholder="Ada Obi" autocomplete="name" maxlength="255" required autofocus></span>@error('owner_name')<small>{{ $message }}</small>@enderror</label>
        <div class="inventra-register-row">
            <label class="inventra-login-field"><span>Email</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-mail.svg') }}" alt=""><input type="email" name="email" value="{{ \App\Support\OldInput::scalar('email') }}" placeholder="you@business.com" autocomplete="email" maxlength="255" required></span>@error('email')<small>{{ $message }}</small>@enderror</label>
            <label class="inventra-login-field"><span>Phone</span><span class="inventra-login-control"><input type="tel" name="phone" value="{{ \App\Support\OldInput::scalar('phone') }}" placeholder="0803 000 0000" autocomplete="tel" maxlength="17" required></span>@error('phone')<small>{{ $message }}</small>@enderror</label>
        </div>
        <div class="inventra-register-row">
            <label class="inventra-login-field"><span>Password</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-lock.svg') }}" alt=""><input name="password" x-bind:type="reveal ? 'text' : 'password'" placeholder="At least 8 characters" autocomplete="new-password" required><button type="button" x-on:click="reveal = ! reveal" aria-label="Show or hide password" x-bind:data-tooltip="reveal ? 'Hide password' : 'Show password'"><img src="{{ asset('images/figma/auth-eye.svg') }}" alt=""></button></span>@error('password')<small>{{ $message }}</small>@enderror</label>
            <label class="inventra-login-field"><span>Confirm password</span><span class="inventra-login-control"><img src="{{ asset('images/figma/auth-lock.svg') }}" alt=""><input name="password_confirmation" x-bind:type="reveal ? 'text' : 'password'" placeholder="Repeat your password" autocomplete="new-password" required></span></label>
        </div>

        <p class="inventra-auth-eyebrow">Business</p>
        <label class="inventra-login-field"><span>Business name</span><span class="inventra-login-control"><input name="business_name" value="{{ \App\Support\OldInput::scalar('business_name') }}" placeholder="Obi Auto Parts" autocomplete="organization" maxlength="150" required></span>@error('business_name')<small>{{ $message }}</small>@enderror</label>

        <button type="submit" class="inventra-sign-in" x-bind:disabled="submitting"><span x-show="! submitting">Create account</span><span x-cloak x-show="submitting">Creating your account…</span></button>
    </form>
    <p class="inventra-auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</x-auth-layout>
