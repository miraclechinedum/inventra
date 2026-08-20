<x-auth-layout title="Set a Quick PIN" eyebrow="Optional setup">
    <p class="text-slate-600">Create a four-digit PIN for future quick-unlock features. Your account password remains the primary credential.</p>
    <form method="POST" action="{{ route('onboarding.pin.store') }}" class="mt-7 space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        @csrf
        <x-form-field label="4-digit PIN" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" required autofocus />
        <x-form-field label="Confirm PIN" name="pin_confirmation" type="password" inputmode="numeric" autocomplete="off" maxlength="4" required />
        <x-primary-button type="submit" x-bind:disabled="submitting">Set Quick PIN</x-primary-button>
    </form>
    <form method="POST" action="{{ route('onboarding.pin.skip') }}" class="mt-3">
        @csrf
        <button type="submit" class="w-full rounded-xl px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100">Skip for now</button>
    </form>
</x-auth-layout>
