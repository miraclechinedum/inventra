{{--
    My profile, rebuilt to the Figma.

    Two truths shape what this screen offers:

    - Full name, email and phone are SELF-SERVICE. Every signed-in account edits its own through
      profile.update, which resolves the subject from the session and accepts no user id. Email
      doubles as the login identifier, so it is normalised by the same helper authentication uses;
      role, status and password are not touched by this form and remain governed where they were.
    - There is no Owner concept in the domain: `UserRole` is Admin/Manager/SalesRep and nothing
      records ownership. The badge shows the real role instead of inventing "· OWNER".

    Everything else — the photo, the section rows, the security actions — uses the routes and
    actions that already exist.
--}}
@php($business = app(\App\Settings\BusinessSettings::class)->current())
@php($initials = collect(explode(' ', $user->name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))
@php($staffCount = \App\Models\User::query()->inCurrentBusiness()->count())

<x-app-layout title="My profile">
    <div class="pf-page" x-data="profilePhoto">
        <div class="pf-head">
            <h1>My profile</h1>
            {{-- The real role. Inventra has no ownership concept to report. --}}
            <span class="pf-role-badge">{{ mb_strtoupper($user->role->label()) }}</span>
        </div>

        <x-validation-errors />

        {{-- ── Identity ─────────────────────────────────────────────────────────────────────── --}}
        <section class="pf-identity">
            <span class="pf-avatar" aria-hidden="true">
                @if ($user->photo_path)
                    <img src="{{ route('users.photo', $user) }}" alt="" width="72" height="72">
                @else
                    {{ $initials }}
                @endif
            </span>
            <span class="pf-identity-body">
                <strong>{{ $user->name }}</strong>
                <span>{{ $user->role->label() }} · {{ $business->business_name }}</span>
            </span>

            {{-- Opens the existing upload form below, which posts to profile.photo.store. --}}
            <button type="button" class="pf-photo-button" x-on:click="toggle"
                    x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="pf-photo-panel">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>
                Change photo
            </button>
        </section>

        {{-- The real upload, using the existing validated route. Hidden until asked for, so the
             Figma's header stays clean without removing the capability. --}}
        <section class="pf-photo-panel" id="pf-photo-panel" x-cloak x-show="open">
            <form method="POST" action="{{ route('profile.photo.store') }}" enctype="multipart/form-data" class="pf-photo-form">
                @csrf
                <label class="pf-field pf-photo-field">
                    <span class="pf-label" for="pf-photo">Profile photo</span>
                    <input type="file" id="pf-photo" name="photo" accept="image/jpeg,image/png,image/webp" required
                           aria-describedby="pf-photo-hint">
                </label>
                <p class="pf-hint" id="pf-photo-hint">JPG, PNG or WebP · up to 2&nbsp;MB · maximum 4000&times;4000.</p>
                @error('photo')<p class="pf-error" role="alert">{{ $message }}</p>@enderror
                <div class="pf-photo-actions">
                    <button type="button" class="wa-button" x-on:click="toggle">Cancel</button>
                    <button type="submit" class="inventra-primary-action">{{ $user->photo_path ? 'Replace photo' : 'Upload photo' }}</button>
                </div>
            </form>

            @if ($user->photo_path)
                <div class="pf-photo-remove">
                    <x-confirm-action :action="route('profile.photo.destroy')" label="Remove photo"
                        message="Remove your profile photo? Your initials will be shown instead." destructive method="DELETE" />
                </div>
            @endif
        </section>

        {{-- ── Personal information ─────────────────────────────────────────────────────────── --}}
        {{-- Self-service. The form posts to profile.update, which resolves the account from the
             session and takes no user id, so this cannot be aimed at anyone else. Only name, email
             and phone are submitted; role, status, password and the photograph are each governed
             elsewhere. --}}
        <form method="POST" action="{{ route('profile.update') }}" class="pf-fields" data-submit-once>
            @csrf
            @method('PUT')

            <div class="pf-grid">
                <div class="pf-field">
                    <label class="pf-label" for="pf-name">Full name</label>
                    <input id="pf-name" name="name" type="text" required maxlength="255" autocomplete="name"
                           @class(['is-invalid' => $errors->has('name')])
                           value="{{ \App\Support\OldInput::scalar('name', $user->name) }}">
                    @error('name')<p class="pf-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="pf-phone">Phone</label>
                    {{-- Optional, as it is in the domain. Friendly input is accepted and stored in
                         the project's canonical +234 form. --}}
                    <input id="pf-phone" name="phone" type="text" inputmode="tel" maxlength="20" autocomplete="tel"
                           placeholder="0802 555 0190"
                           @class(['is-invalid' => $errors->has('phone')])
                           value="{{ \App\Support\OldInput::scalar('phone', $user->phone ?? '') }}">
                    @error('phone')<p class="pf-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="pf-field">
                <label class="pf-label" for="pf-email">Email</label>
                {{-- This is also the login identifier. Changing it changes how the account signs
                     in, which is why it is normalised exactly as authentication normalises it. --}}
                <input id="pf-email" name="email" type="email" required maxlength="255" autocomplete="email"
                       @class(['is-invalid' => $errors->has('email')])
                       value="{{ \App\Support\OldInput::scalar('email', $user->email) }}">
                @error('email')<p class="pf-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="pf-fields-foot">
                <button type="submit" class="inventra-primary-action">Save changes</button>
            </div>
        </form>

        {{-- ── Business & team ──────────────────────────────────────────────────────────────── --}}
        <section class="pf-section">
            <p class="pf-section-label">Business &amp; team</p>

            @can('view', \App\Models\BusinessSetting::class)
                <a href="{{ route('settings.business.edit') }}" class="pf-row">
                    <span class="pf-row-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 4.5 4h15L21 9.5M3 9.5h18M3 9.5v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-10M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>
                    </span>
                    <span class="pf-row-body"><strong>Business profile</strong><small>Name, logo, address, currency</small></span>
                    <svg class="pf-row-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            @endcan

            @can('viewAny', \App\Models\User::class)
                <a href="{{ route('staff.index') }}" class="pf-row">
                    <span class="pf-row-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>
                    </span>
                    <span class="pf-row-body"><strong>Staff &amp; roles</strong><small>{{ $staffCount }} {{ \Illuminate\Support\Str::plural('member', $staffCount) }} · manage access</small></span>
                    <svg class="pf-row-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            @endcan

            {{-- Billing does not exist in Inventra. Rendered disabled exactly as the Figma shows,
                 and deliberately not a link: there is nothing to navigate to. --}}
            <div class="pf-row is-disabled" aria-disabled="true">
                <span class="pf-row-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                </span>
                <span class="pf-row-body"><strong>Billing &amp; plan</strong><small>Coming soon</small></span>
                <svg class="pf-row-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </div>
        </section>

        {{-- ── Security ─────────────────────────────────────────────────────────────────────── --}}
        <section class="pf-section">
            <p class="pf-section-label">Security</p>
            <div class="pf-security">
                {{-- The existing password flow. Inventra has no in-app "change password" screen for
                     a signed-in user, so this uses the established reset-by-email route rather than
                     a second password implementation. --}}
                <a href="{{ route('password.request') }}" class="pf-security-action">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M14 9l2 2"/></svg>
                    Change password
                </a>

                <button type="button" class="pf-security-action is-danger" x-data="logoutTrigger" x-on:click="ask">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
                    Log out
                </button>
            </div>
        </section>
    </div>
</x-app-layout>
