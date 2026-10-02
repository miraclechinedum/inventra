{{--
    The three-step connection flow.

    Each step's state comes from the server: step 2 is only reachable after the server accepted a
    number and the provider accepted the code message, and step 3 only after the server verified the
    code and the provider confirmed the number. The browser never decides that a connection
    succeeded, and there is no client-side code generation or checking anywhere in this file.
--}}
<div class="wa-modal" x-cloak x-show="connect.open" x-on:keydown.escape.stop="closeConnect"
     x-on:keydown.tab="trapConnect" role="dialog" aria-modal="true" aria-labelledby="wa-connect-title">
    <div class="wa-modal-backdrop" x-on:click="closeConnect" aria-hidden="true"></div>

    <div class="wa-modal-panel wa-connect" x-ref="connectPanel">
        <header class="wa-modal-head">
            <h2 id="wa-connect-title" x-text="connect.step === 3 ? '' : 'Connect WhatsApp'"></h2>
            <button type="button" class="wa-icon-button" x-on:click="closeConnect" aria-label="Close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>

        <div class="wa-modal-body">
            {{-- Progress. `aria-current` marks the live step so it is not conveyed by colour alone. --}}
            <ol class="wa-steps" x-show="connect.step < 3" aria-label="Connection progress">
                <template x-for="n in [1, 2, 3]" :key="n">
                    <li class="wa-step"
                        x-bind:class="connect.step > n ? 'is-done' : (connect.step === n ? 'is-current' : '')"
                        x-bind:aria-current="connect.step === n ? 'step' : false">
                        <span class="wa-step-dot">
                            <svg x-show="connect.step > n" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>
                            <span x-show="connect.step <= n" x-text="n"></span>
                        </span>
                    </li>
                </template>
            </ol>

            {{-- ── Step 1 ── --}}
            <div x-show="connect.step === 1">
                <h3 class="wa-connect-title">Which number should we connect?</h3>
                <p class="wa-connect-note">Use the business's WhatsApp number. You'll confirm it with Meta in the next step.</p>

                <label class="wa-field">
                    <span class="wa-label">WhatsApp business number</span>
                    <span class="wa-input-wrap">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z"/></svg>
                        <input type="tel" class="wa-connect-input" x-model="connect.phone" x-ref="connectPhone"
                               placeholder="+234 700 000 1234" autocomplete="tel"
                               x-bind:aria-invalid="connect.error ? 'true' : 'false'"
                               aria-describedby="wa-connect-requirement">
                    </span>
                </label>

                <p class="wa-info" id="wa-connect-requirement">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8v.01"/></svg>
                    The number must have WhatsApp installed and be able to receive messages.
                </p>
            </div>

            {{-- ── Step 2 ── --}}
            <div x-show="connect.step === 2">
                <h3 class="wa-connect-title">Connect through Meta</h3>
                <p class="wa-connect-note">
                    Meta will securely connect this business's WhatsApp account. You'll sign in with
                    Meta, choose the WhatsApp Business Account and confirm the number.
                </p>

                {{-- What Meta will do, in the modal's own visual language. This replaces the
                     six-cell OTP: Inventra cannot issue a code that claims a number — only Meta can
                     grant a business's sending identity, and Embedded Signup is how it does that. --}}
                <ul class="wa-meta-steps">
                    <li><span aria-hidden="true">1</span>Sign in to Meta and accept the WhatsApp terms</li>
                    <li><span aria-hidden="true">2</span>Pick the WhatsApp Business Account and number</li>
                    <li><span aria-hidden="true">3</span>Return here — we'll finish the connection</li>
                </ul>

                {{-- Meta requires the number's two-step verification PIN to register it for
                     messaging: the existing PIN if it has one, otherwise the PIN it will be given.
                     It is sent once over HTTPS for that registration and never stored. --}}
                <label class="wa-field">
                    <span class="wa-label">Two-step verification PIN</span>
                    <span class="wa-input-wrap">
                        <input type="password" class="wa-connect-input" x-model="connect.pin"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off"
                               placeholder="6 digits" aria-describedby="wa-connect-pin-note">
                    </span>
                </label>
                <p class="wa-connect-note" id="wa-connect-pin-note">
                    Use the number's existing WhatsApp two-step PIN, or choose six digits to set one. Inventra does not keep it.
                </p>

                <p class="wa-info">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8v.01"/></svg>
                    A Meta window will open. Keep this page open until it finishes.
                </p>

                {{-- Errors are announced, not merely reddened. --}}
                <p class="wa-form-error" x-show="connect.error" role="alert">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 13V8M12 16v.01"/></svg>
                    <span x-text="connect.error"></span>
                </p>
            </div>

            {{-- ── Step 3 ── --}}
            <div class="wa-success" x-show="connect.step === 3">
                <span class="wa-success-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
                </span>
                <h3>WhatsApp connected</h3>
                <p><b x-text="connect.connectedPhone || connect.phone"></b> is ready. Turn on automations to start sending messages.</p>
            </div>

            {{-- Step 1 errors, announced. A compact inline alert rather than a bordered block:
                 the same shape as the info callout above it, so a refusal reads as a note about
                 the field rather than as another input. --}}
            <p class="wa-form-error" x-show="connect.step === 1 && connect.error" role="alert">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 13V8M12 16v.01"/></svg>
                <span x-text="connect.error"></span>
            </p>
        </div>

        <footer class="wa-modal-foot" x-show="connect.step < 3">
            <button type="button" class="wa-button" x-on:click="connect.step === 1 ? closeConnect() : backToPhone()"
                    x-text="connect.step === 1 ? 'Cancel' : 'Back'"></button>
            <button type="button" class="wa-button is-primary" x-bind:disabled="connect.busy || (connect.step === 2 && connect.preparing)"
                    x-on:click="connect.step === 1 ? continueToMeta() : startEmbeddedSignup()"
                    x-text="connect.busy ? 'Working…' : (connect.step === 1 ? 'Continue' : (connect.preparing ? 'Preparing…' : 'Continue with Meta'))"></button>
        </footer>

        <footer class="wa-modal-foot is-stacked" x-show="connect.step === 3">
            <button type="button" class="wa-button is-primary is-block" x-on:click="finishConnect(true)">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h10M18 18h2"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="16" cy="18" r="2"/></svg>
                Set up automations
            </button>
            <button type="button" class="wa-button is-block" x-on:click="finishConnect(false)">Done</button>
        </footer>
    </div>
</div>
