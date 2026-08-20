<x-auth-layout title="Welcome back">
    <p class="text-slate-600">Sign in with your work email or phone number.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-7 space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-form-field label="Email or phone" name="identifier" autocomplete="username" required autofocus />
        <x-form-field label="Password" name="password" type="password" autocomplete="current-password" required />
        <div class="flex items-center justify-between gap-4 text-sm">
            <label class="flex items-center gap-2 text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-[#0b56c9] focus:ring-blue-200">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="font-semibold text-[#0b56c9] hover:underline">Forgot password?</a>
        </div>
        <x-primary-button type="submit" x-bind:disabled="submitting">
            <span x-show="! submitting">Sign in</span>
            <span x-cloak x-show="submitting">Signing in…</span>
        </x-primary-button>
    </form>
</x-auth-layout>
