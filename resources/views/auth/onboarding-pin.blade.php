<x-auth-layout title="Create your Quick PIN" eyebrow="Optional setup" subtitle="Use a memorable four-digit PIN for faster access.">
    <form method="POST" action="{{ route('onboarding.pin.store') }}" class="space-y-5" x-data="quickPinSetup(4)" x-on:submit="submit" x-on:keydown.window="keydown">
        @csrf
        <input type="hidden" name="pin" x-bind:value="pin"><input type="hidden" name="pin_confirmation" x-bind:value="confirmation">
        <p class="text-center text-[13px] text-[#64748B]" aria-live="polite" x-text="prompt()">Enter a 4-digit PIN.</p>
        <div class="inventra-pin-dots" aria-label="Four digit PIN"><template x-for="index in 4"><span x-bind:class="dotClass(index)"></span></template></div>
        <span class="inventra-field-error text-center" role="alert" x-cloak x-show="mismatch">The PINs did not match. Enter your new PIN again.</span>
        @error('pin')<span class="inventra-field-error text-center">{{ $message }}</span>@enderror
        <div class="inventra-keypad">@foreach([1,2,3,4,5,6,7,8,9] as $digit)<button type="button" x-on:click="press({{ $digit }})">{{ $digit }}</button>@endforeach<span></span><button type="button" x-on:click="press(0)">0</button><button type="button" aria-label="Delete digit" data-tooltip="Delete digit" x-on:click="remove">⌫</button></div>
        <x-primary-button type="submit" x-bind:disabled="blocked()">Set Quick PIN</x-primary-button>
    </form>
    <form method="POST" action="{{ route('onboarding.pin.skip') }}" class="mt-3">@csrf<button type="submit" class="w-full rounded-[9px] px-5 py-3 text-[13px] font-semibold text-[#64748B] hover:bg-[#F8FAFC]">Skip for now</button></form>
</x-auth-layout>
