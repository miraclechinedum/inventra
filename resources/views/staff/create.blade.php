{{--
    Add staff.

    Create-only markup. `staff/_form.blade.php` is shared with Edit, so this screen carries its own
    presentation rather than restyling that partial — Edit is out of scope and must not change.
    Nothing about the domain is duplicated: the same route, the same StoreStaffRequest and the same
    CreateStaff action run behind it.

    There is deliberately no temporary-password field here. The credential is generated server-side
    by CreateStaff (`Str::password(20)`), hashed by the model, and revealed exactly once on the
    page that follows. A password input would mean the browser chose the credential, which is both
    weaker and a different security model; the design's password row belongs to that success page,
    where it is the one moment the plaintext genuinely exists.

    Every class is `stf-` prefixed and scoped under `.stf-create`, so none of it reaches the Staff
    index, Edit, or any other screen.
--}}
@php($role = \App\Support\OldInput::scalar('role', 'manager'))

<x-app-layout title="Add staff">
    <div class="stf-create">
        <div class="stf-create-head">
            <div>
                <p class="stf-crumbs">
                    <a href="{{ route('staff.index') }}">Staff accounts</a>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    <b>Add staff</b>
                </p>
                <h1>Add staff account</h1>
                {{-- Administrator is absent from the role cards below by design, not omission. --}}
                <p class="stf-create-note">
                    Create a Manager or Sales Representative. Administrator accounts can only be
                    bootstrapped from the command line.
                </p>
            </div>
            <a href="{{ route('staff.index') }}" class="stf-close" aria-label="Close" data-tooltip="Close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </a>
        </div>

        <form method="POST" action="{{ route('staff.store') }}" class="stf-form" data-submit-once>
            @csrf

            {{-- A plan's staff limit is refused here, before any account is created. --}}
            @error('staff')<p class="stf-error" role="alert">{{ $message }}</p>@enderror

            <div class="stf-form-body">
                <label class="stf-field">
                    <span class="stf-label">Full name <i aria-hidden="true">*</i></span>
                    <input name="name" required maxlength="255" autocomplete="name" autofocus
                           @class(['is-invalid' => $errors->has('name')])
                           value="{{ \App\Support\OldInput::scalar('name', '') }}">
                    @error('name')<p class="stf-error">{{ $message }}</p>@enderror
                </label>

                <label class="stf-field">
                    <span class="stf-label">Email address <i aria-hidden="true">*</i></span>
                    <input type="email" name="email" required maxlength="255" autocomplete="email"
                           @class(['is-invalid' => $errors->has('email')])
                           value="{{ \App\Support\OldInput::scalar('email', '') }}">
                    {{-- Duplicate-email errors belong here, under the field that caused them. The
                         server raises them on the `email` key both in StoreStaffRequest's unique
                         rule and in the controller's constraint-violation fallback. --}}
                    @error('email')<p class="stf-error">{{ $message }}</p>@enderror
                </label>

                {{-- Optional, and styled like the two above so it reads as part of the form rather
                     than something bolted on. The server still enforces its uniqueness and its
                     Nigerian-number normalisation. --}}
                <label class="stf-field">
                    <span class="stf-label">Phone number</span>
                    <input name="phone" inputmode="tel" maxlength="17" autocomplete="tel"
                           placeholder="0801 234 5678"
                           @class(['is-invalid' => $errors->has('phone')])
                           value="{{ \App\Support\OldInput::scalar('phone', '') }}">
                    @error('phone')<p class="stf-error">{{ $message }}</p>@enderror
                </label>

                {{-- ── Role ──────────────────────────────────────────────────────────────────────
                     Real radios inside real labels, so the whole card is clickable, the keyboard
                     works and each option is announced with its description. The copy is UI text:
                     what a Manager or Sales Representative may actually do is decided by the role
                     enum and the policies, never by these sentences. --}}
                <fieldset class="stf-roles" x-data="roleCards">
                    <legend class="stf-label">Role <i aria-hidden="true">*</i></legend>

                    @foreach([
                        ['manager', 'Manager', 'Runs inventory, sales & customers. No staff or settings access.'],
                        ['sales_rep', 'Sales Representative', "Records sales & views stock. Can't edit or delete."],
                    ] as [$value, $title, $description])
                        <label class="stf-role" x-bind:class="chosen === '{{ $value }}' ? 'is-selected' : ''">
                            <input type="radio" name="role" value="{{ $value }}" required
                                   x-model="chosen"
                                   @checked($role === $value)
                                   aria-describedby="role-{{ $value }}-description">
                            <span class="stf-role-radio" aria-hidden="true"></span>
                            <span class="stf-role-body">
                                <span class="stf-role-title">{{ $title }}</span>
                                <span class="stf-role-description" id="role-{{ $value }}-description">{{ $description }}</span>
                            </span>
                        </label>
                    @endforeach

                    @error('role')<p class="stf-error">{{ $message }}</p>@enderror
                </fieldset>
            </div>

            <div class="stf-form-foot">
                <a href="{{ route('staff.index') }}" class="stf-button">Cancel</a>
                <button type="submit" class="inventra-primary-action">
                    <img src="{{ asset('images/figma/icon-plus.svg') }}" alt="">Add Staff
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
