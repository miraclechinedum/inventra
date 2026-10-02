{{--
    WhatsApp consent on the edit screen.

    A separate form on purpose. Consent is not a profile field: UpdateCustomerRequest prohibits
    `whatsapp_opt_in`, and SetWhatsAppConsent records the change under a row lock, stamps the
    opt-in/opt-out timestamp and writes its own audit event. Folding it into the profile save would
    either bypass that or duplicate it, so this posts to the endpoint that already exists.

    It renders as part of the form visually and submits independently, which is also why it sits
    outside the <form> above: HTML has no nested forms.

    Whoever may not change consent sees the state without a control, rather than a control that
    would be refused.
--}}
@php($canChangeConsent = auth()->user()->can('changeConsent', $customer))

<form method="POST" action="{{ route('customers.consent', $customer) }}" class="cust-consent-form" data-submit-once>
    @csrf
    {{-- The opposite of the current state: this button toggles, and the server decides what that
         means under the lock. A stale page therefore cannot assert a value, only a direction. --}}
    <input type="hidden" name="opt_in" value="{{ $customer->whatsapp_opt_in ? '0' : '1' }}">

    <div @class(['cust-consent', 'is-static', 'is-off' => ! $customer->whatsapp_opt_in])>
        <span @class(['cust-check-box', 'is-green', 'is-on' => $customer->whatsapp_opt_in]) aria-hidden="true">
            @if($customer->whatsapp_opt_in)
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3"
                     stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            @endif
        </span>

        <span class="cust-consent-body">
            <b>Receive WhatsApp updates</b>
            @if($customer->whatsapp_opt_in)
                <span>Customer consents to automated order &amp; follow-up messages.</span>
            @else
                {{-- Never claims consent that was not given, and never implies a number is one. --}}
                <span>This customer has not consented to automated messages.</span>
            @endif
        </span>

        @if($canChangeConsent)
            <button type="submit" class="cust-consent-action">
                {{ $customer->whatsapp_opt_in ? 'Withdraw consent' : 'Record consent' }}
            </button>
        @endif
    </div>

    @error('customer')<p class="inventra-field-error">{{ $message }}</p>@enderror
    @error('opt_in')<p class="inventra-field-error">{{ $message }}</p>@enderror
</form>
