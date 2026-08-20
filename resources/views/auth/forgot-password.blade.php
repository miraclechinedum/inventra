<x-auth-layout title="Reset your password">
    <p class="text-slate-600">Enter your work email and we’ll send password reset instructions if the account exists.</p>
    @if (session('status'))
        <div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="mt-7 space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-form-field label="Email address" name="email" type="email" autocomplete="email" required autofocus />
        <x-primary-button type="submit" x-bind:disabled="submitting">
            <span x-show="! submitting">Send reset instructions</span>
            <span x-cloak x-show="submitting">Sending…</span>
        </x-primary-button>
        <a href="{{ route('login') }}" class="block text-center text-sm font-semibold text-[#0b56c9] hover:underline">Back to sign in</a>
    </form>
</x-auth-layout>
