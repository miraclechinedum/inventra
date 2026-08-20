<x-auth-layout title="Choose a new password">
    <p class="text-slate-600">Use at least eight characters with uppercase, lowercase and a number.</p>
    <form method="POST" action="{{ route('password.update') }}" class="mt-7 space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-form-field label="Email address" name="email" type="email" autocomplete="email" :value="$email" required />
        <x-form-field label="New password" name="password" type="password" autocomplete="new-password" required />
        <x-form-field label="Confirm new password" name="password_confirmation" type="password" autocomplete="new-password" required />
        <x-primary-button type="submit" x-bind:disabled="submitting">Reset password</x-primary-button>
    </form>
</x-auth-layout>
