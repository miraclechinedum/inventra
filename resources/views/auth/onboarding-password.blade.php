<x-auth-layout title="Create your password" eyebrow="Account setup">
    <p class="text-slate-600">Replace your temporary password before continuing to Inventra.</p>
    <form method="POST" action="{{ route('onboarding.password.update') }}" class="mt-7 space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-form-field label="New password" name="password" type="password" autocomplete="new-password" required autofocus />
        <x-form-field label="Confirm new password" name="password_confirmation" type="password" autocomplete="new-password" required />
        <p class="text-sm leading-6 text-slate-500">Minimum eight characters, including uppercase, lowercase and a number. A symbol is recommended.</p>
        <x-primary-button type="submit" x-bind:disabled="submitting">Save password</x-primary-button>
    </form>
</x-auth-layout>
