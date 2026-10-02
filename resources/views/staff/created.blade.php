{{--
    The one-time credential reveal.

    This is the only moment the temporary password exists in plaintext anywhere. CreateStaff
    generated it with `Str::password(20)`, the model hashed it on save, and the controller handed
    the plaintext straight to this view. It is not stored, not flashed to the session, not written
    to the audit trail, and not in the URL — so once this response is gone, it is gone.

    Which is why the Copy button matters here rather than on the form before it: copying reads the
    text already rendered on this page. It does not regenerate anything, does not ask the server,
    and does not put the credential into localStorage, sessionStorage or anywhere else.
--}}
<x-app-layout title="Staff account created">
    <div class="stf-created">
        <div class="stf-created-card">
            <span class="stf-created-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            </span>
            <h1>Account created for {{ $staffMember->name }}</h1>
            <p class="stf-created-sub">{{ $staffMember->email }} &middot; {{ $staffMember->role->label() }}</p>

            <div class="stf-credential" x-data="copyValue">
                <p class="stf-label">Temporary password</p>
                <div class="stf-credential-row">
                    {{-- `readonly`, not `disabled`: it stays selectable and copyable by hand, and is
                         announced properly, but nothing here posts it anywhere. --}}
                    <input class="stf-credential-value" x-ref="value" readonly
                           value="{{ $temporaryPassword }}" aria-label="Temporary password"
                           onfocus="this.select()">
                    <button type="button" class="stf-button stf-copy" x-on:click="copy"
                            x-bind:aria-label="copied ? 'Password copied' : 'Copy temporary password'">
                        <svg x-show="!copied" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        <svg x-show="copied" x-cloak viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
                        <span x-text="copied ? 'Copied' : 'Copy'"></span>
                    </button>
                </div>
                {{-- Announced when it changes, so the confirmation reaches a screen reader without
                     a toast the rest of Inventra does not use for this. --}}
                <p class="stf-credential-live" role="status" aria-live="polite" x-text="copied ? 'Temporary password copied to the clipboard.' : ''"></p>
            </div>

            <p class="stf-created-warning">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17v.01"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                This password will not be shown again. Share it securely with the staff member &mdash;
                they will set their own during first login.
            </p>
        </div>

        <div class="stf-created-actions">
            <a href="{{ route('staff.create') }}" class="stf-button">Add another</a>
            <a href="{{ route('staff.show', $staffMember) }}" class="inventra-primary-action">View staff account</a>
        </div>
    </div>
</x-app-layout>
