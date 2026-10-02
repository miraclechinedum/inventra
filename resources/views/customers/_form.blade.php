{{--
    The customer form, shared by create and edit.

    One partial so the two cannot drift: the same fields, the same FormRequest, the same action and
    the same policy. `$customer` being null is what distinguishes the modes — it changes the button
    label, the photo controls and where the form posts, never which fields exist or what they carry.

    Immutable fields are absent by construction rather than hidden: CustomerProfileRequest prohibits
    customer_code, is_active, the consent timestamps and the audit columns outright, so nothing here
    could smuggle one through even if it tried.

    Two ideas the form deliberately keeps apart:

      whatsapp_phone   *where* a WhatsApp message would go. The "same as phone" tick means there is
                       no separate destination, so the column stays null rather than holding a
                       second copy of the phone.
      whatsapp_opt_in  *whether* one may be sent at all. Consent. Having a number is never consent.
--}}
@php($isEdit = isset($customer) && $customer !== null)
@php($hasSeparateWhatsApp = $isEdit && ! $customer->whatsAppUsesPhone())

<form method="POST"
      action="{{ $isEdit ? route('customers.update', $customer) : route('customers.store') }}"
      enctype="multipart/form-data"
      class="cust-form"
      x-data="customerForm"
      data-separate-whatsapp="{{ $hasSeparateWhatsApp ? '1' : '0' }}"
      data-initials="{{ $isEdit ? \App\Support\Initials::from($customer->full_name) : '' }}"
      @if($isEdit && $customer->photo_path) data-remove-photo="{{ route('customers.photo.destroy', $customer) }}" @endif
      data-csrf="{{ csrf_token() }}"
      data-submit-once>
    @csrf
    @if($isEdit)@method('PUT')@endif

    <div class="cust-form-body">
        {{-- ── Photograph ────────────────────────────────────────────────────────────────── --}}
        <div class="cust-photo-row">
            @if($isEdit && $customer->photo_path)
                <img src="{{ route('customers.photo', $customer) }}" alt="{{ $customer->full_name }}"
                     class="cust-avatar is-lg" x-ref="preview">
            @else
                {{-- The initials stand in until a file is chosen, and the preview swaps in place. --}}
                <span class="cust-avatar is-lg is-initials" x-ref="placeholder"
                      x-show="!previewUrl" aria-hidden="true">{{ $isEdit ? \App\Support\Initials::from($customer->full_name) : '' }}</span>
                <img x-show="previewUrl" x-cloak x-bind:src="previewUrl" alt="" class="cust-avatar is-lg">
            @endif

            <div class="cust-photo-actions">
                @if($isEdit && $customer->photo_path)
                    {{-- An existing photo is replaced or removed, never lost by omission: submitting
                         the form without choosing a file leaves it exactly as it is. --}}
                    {{-- Neutral, not primary: replacing a photograph sits beside a destructive
                         action, and neither is the page's main call to action — Save Change is. --}}
                    <label class="cust-button">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 2v6h-6"/><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/></svg>
                        Replace
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                               class="cust-file" x-on:change="preview">
                    </label>
                    <button type="button" class="cust-button is-danger" x-on:click="removePhoto">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6"/></svg>
                        Remove
                    </button>
                    {{-- Photographs appear in the customer list and on the profile. They are not
                         rendered on receipts, so the copy does not claim they are. --}}
                    <p class="cust-photo-hint">Shows in lists &amp; profile.</p>
                @else
                    <label class="cust-button is-primary">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 9 5-5 5 5"/><path d="M12 4v12"/></svg>
                        Upload photo
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                               class="cust-file" x-on:change="preview">
                    </label>
                    {{-- `capture` asks a phone for its camera and is ignored on a desktop, which
                         falls back to an ordinary file picker. No camera API, nothing to permit. --}}
                    <label class="cust-button">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Take photo
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                               capture="environment" class="cust-file" x-on:change="preview">
                    </label>
                    {{-- The real limit, which ImageStore enforces — not a larger number the
                         validator would then reject. --}}
                    <p class="cust-photo-hint">JPG or PNG &middot; up to 2MB &middot; square works best</p>
                @endif
                @error('photo')<p class="inventra-field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- ── Identity ──────────────────────────────────────────────────────────────────── --}}
        <label class="cust-field">
            <span class="cust-label">Customer name <i aria-hidden="true">*</i></span>
            <input name="first_name" required maxlength="100" autocomplete="name"
                   value="{{ \App\Support\OldInput::scalar('first_name', $isEdit ? $customer->first_name : '') }}"
                   @if($isEdit && ! $canUpdateIdentity) disabled @endif>
            @error('first_name')<p class="inventra-field-error">{{ $message }}</p>@enderror
        </label>

        <label class="cust-field">
            <span class="cust-label">Surname</span>
            <input name="last_name" maxlength="100"
                   value="{{ \App\Support\OldInput::scalar('last_name', $isEdit ? (string) $customer->last_name : '') }}"
                   @if($isEdit && ! $canUpdateIdentity) disabled @endif>
            @error('last_name')<p class="inventra-field-error">{{ $message }}</p>@enderror
        </label>

        <label class="cust-field">
            <span class="cust-label">Phone number <i aria-hidden="true">*</i></span>
            <span class="cust-input-icon @error('phone') is-invalid @enderror">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
                <input name="phone" required inputmode="tel" autocomplete="tel"
                       value="{{ \App\Support\OldInput::scalar('phone', $isEdit ? $customer->phone : '') }}"
                       @if($isEdit && ! $canUpdateIdentity) disabled @endif>
            </span>
            {{-- The duplicate message names the existing customer and links to them, so the
                 operator can see who holds the number rather than guessing. The server decides
                 this; `$duplicate` is only set when it did. --}}
            @error('phone')
                <p class="inventra-field-error cust-duplicate">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
                    <span>{{ $message }}</span>
                    @isset($duplicate)
                        <a href="{{ route('customers.show', $duplicate) }}">View profile</a>
                    @endisset
                </p>
            @enderror
        </label>

        {{-- ── WhatsApp destination ──────────────────────────────────────────────────────── --}}
        <label class="cust-check">
            <input type="checkbox" name="whatsapp_same_as_phone" value="1" x-model="sameAsPhone">
            <span class="cust-check-box" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3"
                     stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            </span>
            <span>WhatsApp same as phone number</span>
        </label>

        {{-- Only shown when the customer keeps WhatsApp on a different line. Ticking the box above
             clears it, so the column returns to null rather than duplicating the phone. --}}
        <label class="cust-field" x-show="!sameAsPhone" x-cloak>
            <span class="cust-label">WhatsApp number</span>
            <span class="cust-input-icon @error('whatsapp_phone') is-invalid @enderror">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.9-.9L3 20l1.1-4.9A8.4 8.4 0 1 1 21 11.5z"/></svg>
                <input name="whatsapp_phone" inputmode="tel"
                       value="{{ \App\Support\OldInput::scalar('whatsapp_phone', $isEdit ? (string) $customer->whatsapp_phone : '') }}">
            </span>
            @error('whatsapp_phone')<p class="inventra-field-error">{{ $message }}</p>@enderror
        </label>

        {{-- ── Contact ───────────────────────────────────────────────────────────────────── --}}
        <label class="cust-field">
            <span class="cust-label">Email</span>
            <span class="cust-input-icon @error('email') is-invalid @enderror">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
                <input type="email" name="email" maxlength="255" autocomplete="email"
                       value="{{ \App\Support\OldInput::scalar('email', $isEdit ? (string) $customer->email : '') }}">
            </span>
            @error('email')<p class="inventra-field-error">{{ $message }}</p>@enderror
        </label>

        <label class="cust-field">
            <span class="cust-label">Tag</span>
            <span class="cust-input-icon @error('tag') is-invalid @enderror">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h9l6 7-6 7H5a2 2 0 0 1-2-2z"/></svg>
                <input name="tag" maxlength="120" placeholder="Vehicle or descriptor"
                       value="{{ \App\Support\OldInput::scalar('tag', $isEdit ? (string) $customer->tag : '') }}">
            </span>
            @error('tag')<p class="inventra-field-error">{{ $message }}</p>@enderror
        </label>

        {{-- ── Consent ───────────────────────────────────────────────────────────────────────
             Consent, not a number. It is recorded only because someone ticked this, never inferred
             from having a phone or from recording a sale.

             On CREATE it rides along with the profile. On EDIT it is deliberately NOT part of this
             form: UpdateCustomerRequest prohibits `whatsapp_opt_in` outright, because changing
             consent is its own audited transition that stamps opt-in/opt-out timestamps under a row
             lock. The edit screen therefore renders the state here — where the design asks for it —
             and submits any change to that existing endpoint instead. See `_consent.blade.php`. --}}
        @unless($isEdit)
            <label class="cust-consent">
                <input type="checkbox" name="whatsapp_opt_in" value="1"
                       @checked(\App\Support\OldInput::scalar('whatsapp_opt_in', '') === '1')>
                <span class="cust-check-box is-green" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3"
                         stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
                </span>
                <span class="cust-consent-body">
                    <b>Receive WhatsApp updates</b>
                    <span>Customer consents to automated order &amp; follow-up messages.</span>
                </span>
            </label>
        @endunless
    </div>

    <div class="cust-form-foot">
        <a href="{{ $isEdit ? route('customers.show', $customer) : route('customers.index') }}" class="cust-button">Cancel</a>
        <button type="submit" class="inventra-primary-action">
            {{ $isEdit ? 'Save Change' : 'Save Customer' }}
        </button>
    </div>
</form>
