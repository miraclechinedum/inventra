{{--
    Business profile, rebuilt to the Figma.

    The fields shown are exactly the Figma's. Three notes on what that means here:

    - The Figma's logo caption reads "Logo appears on receipts & WhatsApp messages". Nothing
      consumes `logo_path` yet — receipt templates and WhatsApp templates were not changed — so the
      caption says only what is true today.
    - `business_email`, `city` and `state` were removed from this SCREEN, not from the database.
      They are absent from BusinessSetting::EDITABLE and prohibited by the request, so an ordinary
      save cannot blank the values they still hold. See PRESERVED_NOT_EDITABLE.
    - Disconnect is rendered unavailable. Removing the row locally while Meta still holds the
      webhook subscription would leave the connection live at the provider and broken here.

    Manager alert number is the single destination for low-stock alerts; the automation editor's
    staff-recipient picker was withdrawn in the same change so the two cannot disagree.

    Everything posts through the existing UpdateBusinessSettings action and its validation.
--}}
@php($initials = collect(explode(' ', $settings->business_name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))

<x-app-layout title="Business profile">
    <div class="bp-page" x-data="businessLogo">
        <div class="bp-head">
            <a href="{{ route('profile.edit') }}" class="bp-back" aria-label="Back to my profile">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <h1>Business profile</h1>
        </div>

        <x-validation-errors />

        {{-- ── Identity ─────────────────────────────────────────────────────────────────────── --}}
        <section class="bp-identity">
            <span class="bp-logo" aria-hidden="true">
                @if ($settings->logo_path)
                    <img src="{{ route('settings.business.logo') }}" alt="" width="64" height="64">
                @else
                    {{ $initials }}
                @endif
            </span>
            <span class="bp-identity-body">
                <strong>{{ $settings->business_name }}</strong>
                {{-- Truthful: nothing renders this logo yet. --}}
                <span>Business logo</span>
            </span>

            @can('update', \App\Models\BusinessSetting::class)
                <button type="button" class="bp-logo-button" x-on:click="toggle"
                        x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="bp-logo-panel">
                    Upload logo
                </button>
            @endcan
        </section>

        @can('update', \App\Models\BusinessSetting::class)
            <section class="bp-logo-panel" id="bp-logo-panel" x-cloak x-show="open">
                <form method="POST" action="{{ route('settings.business.logo.store') }}" enctype="multipart/form-data" class="bp-logo-form">
                    @csrf
                    <label class="bp-field">
                        <span class="bp-label" for="bp-logo">Business logo</span>
                        <input type="file" id="bp-logo" name="logo" accept="image/jpeg,image/png,image/webp" required aria-describedby="bp-logo-hint">
                    </label>
                    <p class="bp-hint" id="bp-logo-hint">JPG, PNG or WebP · up to 2&nbsp;MB · maximum 4000&times;4000.</p>
                    @error('logo')<p class="bp-error" role="alert">{{ $message }}</p>@enderror
                    <div class="bp-logo-actions">
                        <button type="button" class="wa-button" x-on:click="toggle">Cancel</button>
                        <button type="submit" class="inventra-primary-action">{{ $settings->logo_path ? 'Replace logo' : 'Upload logo' }}</button>
                    </div>
                </form>

                @if ($settings->logo_path)
                    <div class="bp-logo-remove">
                        <x-confirm-action :action="route('settings.business.logo.destroy')" label="Remove logo"
                            message="Remove the business logo? Its initials will be shown instead." destructive method="DELETE" />
                    </div>
                @endif
            </section>
        @endcan

        {{-- ── Business details ─────────────────────────────────────────────────────────────── --}}
        <form method="POST" action="{{ route('settings.business.update') }}" class="bp-form" data-submit-once>
            @csrf
            @method('PUT')

            <div class="bp-grid">
                <div class="bp-field">
                    <label class="bp-label" for="business_name">Business name</label>
                    <input id="business_name" name="business_name" required maxlength="150"
                           @class(['is-invalid' => $errors->has('business_name')])
                           value="{{ \App\Support\OldInput::scalar('business_name', $settings->business_name) }}">
                    @error('business_name')<p class="bp-error">{{ $message }}</p>@enderror
                </div>

                <div class="bp-field">
                    <label class="bp-label" for="business_type">Business type</label>
                    {{-- A controlled select: profile metadata only, and an allowlist server-side. --}}
                    <select id="business_type" name="business_type" @class(['is-invalid' => $errors->has('business_type')])>
                        <option value="">Not set</option>
                        @foreach (\App\Models\BusinessSetting::TYPES as $type)
                            <option value="{{ $type }}" @selected(\App\Support\OldInput::scalar('business_type', $settings->business_type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    @error('business_type')<p class="bp-error">{{ $message }}</p>@enderror
                </div>

                <div class="bp-field">
                    <label class="bp-label" for="business_phone">Phone</label>
                    <input id="business_phone" name="business_phone" inputmode="tel" maxlength="20"
                           @class(['is-invalid' => $errors->has('business_phone')])
                           value="{{ \App\Support\OldInput::scalar('business_phone', $settings->business_phone ?? '') }}">
                    @error('business_phone')<p class="bp-error">{{ $message }}</p>@enderror
                </div>

                <div class="bp-field">
                    <label class="bp-label" for="currency">Currency</label>
                    {{-- Only NGN is offered: every money view renders a hard-coded naira symbol, so
                         listing others would imply a multi-currency system that does not exist. The
                         stored value is an ISO code, so adding one later needs no reshaping. --}}
                    <select id="currency" name="currency" required @class(['is-invalid' => $errors->has('currency')])>
                        @foreach (\App\Models\BusinessSetting::CURRENCIES as $code => $label)
                            <option value="{{ $code }}" @selected(\App\Support\OldInput::scalar('currency', $settings->currency ?? 'NGN') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('currency')<p class="bp-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bp-field">
                <label class="bp-label" for="business_address">Address</label>
                <input id="business_address" name="business_address" maxlength="255"
                       @class(['is-invalid' => $errors->has('business_address')])
                       value="{{ \App\Support\OldInput::scalar('business_address', $settings->business_address ?? '') }}">
                @error('business_address')<p class="bp-error">{{ $message }}</p>@enderror
            </div>

            <div class="bp-grid">
                <div class="bp-field">
                    <label class="bp-label" for="tax_number">Tax / VAT no. <span class="bp-optional">(optional)</span></label>
                    <input id="tax_number" name="tax_number" maxlength="40" placeholder="e.g. 0123456-0001"
                           @class(['is-invalid' => $errors->has('tax_number')])
                           value="{{ \App\Support\OldInput::scalar('tax_number', $settings->tax_number ?? '') }}">
                    @error('tax_number')<p class="bp-error">{{ $message }}</p>@enderror
                </div>
                <div class="bp-field">
                    <label class="bp-label" for="receipt_footer">Receipt footer</label>
                    <input id="receipt_footer" name="receipt_footer" maxlength="500"
                           @class(['is-invalid' => $errors->has('receipt_footer')])
                           value="{{ \App\Support\OldInput::scalar('receipt_footer', $settings->receipt_footer ?? '') }}">
                    @error('receipt_footer')<p class="bp-error">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- ── WhatsApp connection ──────────────────────────────────────────────────────── --}}
            <section class="bp-whatsapp">
                <h2>WhatsApp connection</h2>
                <p class="bp-hint">Manage the business number used for automated messages.</p>

                <div class="bp-connection">
                    @if ($connected)
                        <span class="bp-connection-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 0 1-12.2 7.7L3.5 21l1.3-4.8A8.5 8.5 0 1 1 20.5 12Z"/></svg>
                        </span>
                        <span class="bp-connection-body">
                            {{-- Meta's own resolved number, never anything typed locally. --}}
                            <strong>{{ $connection->displayNumber() }}</strong>
                            <small>Business WhatsApp · verified</small>
                        </span>
                        <span class="wa-badge is-active"><span class="wa-dot" aria-hidden="true"></span>Connected</span>
                    @else
                        <span class="bp-connection-icon is-muted" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 0 1-12.2 7.7L3.5 21l1.3-4.8A8.5 8.5 0 1 1 20.5 12Z"/><path d="M3 3l18 18"/></svg>
                        </span>
                        <span class="bp-connection-body">
                            <strong>No business number connected</strong>
                            <small>Automated messages cannot be sent yet.</small>
                        </span>
                        <span class="wa-badge is-inactive"><span class="wa-dot" aria-hidden="true"></span>Not connected</span>
                    @endif
                </div>

                <div class="bp-connection-actions">
                    @if ($connected)
                        {{-- The test send lives with the automation whose approved template it uses;
                             there is no second send path here. --}}
                        <a href="{{ route('whatsapp.automation.index') }}" class="wa-button">Send test</a>
                        {{-- Disabled on purpose: removing the row locally would leave Meta still
                             subscribed and sending, with Inventra unable to see it. --}}
                        <button type="button" class="wa-button is-muted" disabled
                                title="Disconnect will be available after Meta connection management is enabled.">
                            Disconnect
                        </button>
                        <span class="bp-hint">Disconnect will be available after Meta connection management is enabled.</span>
                    @else
                        <a href="{{ route('whatsapp.automation.index') }}" class="inventra-primary-action">Connect business number</a>
                    @endif
                </div>

            </section>

            {{-- ── Manager alert number ─────────────────────────────────────────────────────── --}}
            {{-- The business-level destination for low-stock alerts, and the only one: the
                 automation's staff-recipient picker was withdrawn in the same change, so this
                 field and the message that gets sent cannot disagree. --}}
            <section class="bp-alert-number">
                <h2>Manager alert number</h2>
                <div class="bp-field">
                    <label class="bp-sr-only" for="manager_alert_number">Manager alert number</label>
                    <span class="bp-input-icon">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.6a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.5-1.2a2 2 0 0 1 2.1-.5c.8.3 1.7.6 2.6.7a2 2 0 0 1 1.7 2Z"/></svg>
                        <input id="manager_alert_number" name="manager_alert_number" type="text" inputmode="tel"
                               maxlength="20" placeholder="+234 801 234 5678"
                               @class(['is-invalid' => $errors->has('manager_alert_number')])
                               value="{{ \App\Support\OldInput::scalar('manager_alert_number', $settings->manager_alert_number ?? '') }}">
                    </span>
                    @error('manager_alert_number')<p class="bp-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <p class="bp-hint">Full automation lives under WhatsApp Automation in the sidebar.</p>
            </section>

            <div class="bp-foot">
                <a href="{{ route('profile.edit') }}" class="wa-button">Cancel</a>
                <button type="submit" class="inventra-primary-action">Save changes</button>
            </div>
        </form>
    </div>
</x-app-layout>
