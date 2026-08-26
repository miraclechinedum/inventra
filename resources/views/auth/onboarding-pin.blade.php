<x-auth-layout title="Create your Quick PIN" eyebrow="Optional setup" subtitle="Use a memorable four-digit PIN for faster access.">
    <form method="POST" action="{{ route('onboarding.pin.store') }}" class="space-y-5" x-data="{ pin: '', submitting: false }" x-on:submit="submitting = true">
        @csrf
        <input type="hidden" name="pin" x-bind:value="pin"><input type="hidden" name="pin_confirmation" x-bind:value="pin">
        <div class="inventra-pin-dots" aria-label="Four digit PIN"><template x-for="index in 4"><span x-bind:class="index <= pin.length ? 'is-filled' : ''"></span></template></div>
        @error('pin')<span class="inventra-field-error text-center">{{ $message }}</span>@enderror
        <div class="inventra-keypad">@foreach([1,2,3,4,5,6,7,8,9] as $digit)<button type="button" x-on:click="if (pin.length < 4) pin += '{{ $digit }}'">{{ $digit }}</button>@endforeach<span></span><button type="button" x-on:click="if (pin.length < 4) pin += '0'">0</button><button type="button" aria-label="Delete digit" x-on:click="pin = pin.slice(0, -1)">⌫</button></div>
        <x-primary-button type="submit" x-bind:disabled="submitting || pin.length !== 4">Set Quick PIN</x-primary-button>
    </form>
    <form method="POST" action="{{ route('onboarding.pin.skip') }}" class="mt-3">@csrf<button type="submit" class="w-full rounded-[9px] px-5 py-3 text-[13px] font-semibold text-[#64748B] hover:bg-[#F8FAFC]">Skip for now</button></form>
</x-auth-layout>
