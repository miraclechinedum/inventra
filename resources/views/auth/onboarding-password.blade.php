<x-auth-layout title="Create your password" eyebrow="Account setup" subtitle="Replace your temporary password before continuing to Inventra.">
    <form method="POST" action="{{ route('onboarding.password.update') }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-form-field label="New password" name="password" type="password" autocomplete="new-password" required autofocus />
        <x-form-field label="Confirm new password" name="password_confirmation" type="password" autocomplete="new-password" required />
        <div class="rounded-[9px] border border-[#E7EBF0] bg-[#F8FAFC] p-4 text-[12px] leading-6 text-[#64748B]"><strong class="block text-[#334155]">Your password must include:</strong>At least 8 characters · uppercase and lowercase letters · a number</div>
        <x-primary-button type="submit" x-bind:disabled="submitting">Save password</x-primary-button>
    </form>
</x-auth-layout>
