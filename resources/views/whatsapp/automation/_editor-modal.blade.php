{{--
    The template editor, and the read-only preview, which are the same modal in two modes: Preview
    shows the saved template without editing it, so there is only ever one copy of the content.

    The preview pane renders through Alpine's `x-text`, which sets textContent — so a template body
    containing markup is displayed as characters and can never become markup. The variable engine
    itself never evaluates anything; see WhatsAppTemplate.
--}}
<div class="wa-modal" x-cloak x-show="editor.open" x-on:keydown.escape.stop="closeEditor"
     x-on:keydown.tab="trapEditor" role="dialog" aria-modal="true" aria-labelledby="wa-editor-title">
    <div class="wa-modal-backdrop" x-on:click="closeEditor" aria-hidden="true"></div>

    <div class="wa-modal-panel wa-editor" x-ref="editorPanel">
        <header class="wa-editor-head">
            <span class="wa-row-icon" x-bind:class="'is-' + editor.key" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 0 1-12.2 7.7L3.5 21l1.3-4.8A8.5 8.5 0 1 1 20.5 12Z"/></svg>
            </span>
            <span class="wa-editor-heading">
                <h2 id="wa-editor-title" x-text="editor.title"></h2>
                <p x-text="editor.trigger"></p>
            </span>

            {{-- The same switch as the list row, writing to the same setting. --}}
            <span class="wa-editor-switch" x-show="! editor.readonly">
                <span x-text="editor.enabled ? 'On' : 'Off'"></span>
                <label class="wa-switch">
                    <input type="checkbox" x-model="editor.enabled" x-bind:aria-label="editor.title + ' enabled'">
                    <span class="wa-switch-track" aria-hidden="true"><span class="wa-switch-thumb"></span></span>
                </label>
            </span>

            <button type="button" class="wa-icon-button" x-on:click="closeEditor" aria-label="Close">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>

        <div class="wa-editor-body">
            {{-- ── Left: the editor ── --}}
            <div class="wa-editor-pane">
                {{-- Pickup timing, shown only where a real scheduled event backs it. --}}
                <div class="wa-editor-timing" x-show="editor.key === 'pickup_reminder'">
                    <span class="wa-editor-timing-label">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Send timing
                    </span>
                    <select x-model.number="editor.delayHours" aria-label="Send timing" x-bind:disabled="editor.readonly">
                        <option value="0">Immediately</option>
                        <option value="4">4 hours after ready</option>
                        <option value="24">1 day after ready</option>
                        <option value="48">2 days after ready</option>
                        <option value="72">3 days after ready</option>
                    </select>
                </div>

                {{-- The low-stock recipient picker was withdrawn here on purpose.
                     The destination is now the business's own Manager alert number on Business
                     profile. Keeping this picker as well would leave two editable answers to the
                     same question, free to disagree and to send the alert twice. The pivot, the
                     relation and the eligibility rules behind it are all untouched — only this
                     control is gone — so nothing historical is lost and it can return if a
                     staff-addressed automation is ever wanted again. --}}
                <p class="wa-recipient-note" x-show="editor.key === 'low_stock'">
                    Sent to the Manager alert number on
                    <a href="{{ route('settings.business.edit') }}">Business profile</a>.
                </p>

                {{-- Template status. Meta only accepts a business-initiated message as an APPROVED
                     template, so this states where the mapped template stands and never implies an
                     approval Meta has not granted. --}}
                <div class="wa-template-state">
                    <span class="wa-badge" x-bind:class="editor.templateApproved ? 'is-active' : 'is-inactive'">
                        <span class="wa-dot" aria-hidden="true"></span><span x-text="editor.templateStatus"></span>
                    </span>
                    <span class="wa-template-name-label" x-show="editor.templateName"
                          x-text="editor.templateName + ' · ' + editor.templateLanguage"></span>
                </div>

                <p class="wa-info" x-show="! editor.templateApproved">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8v.01"/></svg>
                    This wording is a <b>draft</b>. WhatsApp only delivers business-initiated
                    messages from a template Meta has approved, so nothing sends until the mapped
                    template is approved.
                </p>

                <div class="wa-editor-tools" x-show="! editor.readonly">
                    <button type="button" class="wa-button is-primary is-small" x-on:click="insertFirstChip">
                        + Add variable
                    </button>
                </div>

                <label class="wa-sr-only" for="wa-editor-body">Message draft</label>
                <textarea id="wa-editor-body" class="wa-editor-input" x-ref="body" x-model="editor.body"
                          x-bind:readonly="editor.readonly" x-bind:maxlength="{{ \App\Support\WhatsApp\WhatsAppTemplate::MAX_LENGTH }}"
                          x-on:input="syncPreview" rows="5"></textarea>

                <p class="wa-editor-meta">
                    <span class="wa-editor-ok">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
                        All details will fill in
                    </span>
                    <span x-text="editor.body.length + ' / {{ \App\Support\WhatsApp\WhatsAppTemplate::MAX_LENGTH }}'"></span>
                </p>

                <div class="wa-chips" x-show="! editor.readonly">
                    <p class="wa-chips-label">Insert a detail</p>
                    <div class="wa-chip-row">
                        <template x-for="(label, token) in editor.chips" :key="token">
                            {{-- Inserts at the caret. The token is a fixed allowlisted string, not
                                 anything the operator typed. --}}
                            <button type="button" class="wa-chip" x-on:click="insertChip(token)" x-text="label"></button>
                        </template>
                    </div>
                </div>

                <p class="wa-form-error" x-show="editor.error" role="alert" x-text="editor.error"></p>
            </div>

            {{-- ── Right: the live preview ── --}}
            <div class="wa-preview-pane">
                <p class="wa-preview-label" x-text="editor.key === 'low_stock' ? 'PREVIEW · TO MANAGER' : 'PREVIEW · TO CUSTOMER'"></p>
                <div class="wa-preview-canvas">
                    <div class="wa-bubble">
                        {{-- x-text, so the rendered sample is inserted as text and never parsed as
                             markup. --}}
                        <p x-text="preview"></p>
                        <span class="wa-bubble-meta">
                            <span x-text="nowLabel"></span>
                            <svg viewBox="0 0 18 12" width="15" height="11" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m1 6.5 3 3L10 3"/><path d="m7.5 6.5 3 3L17 3"/></svg>
                        </span>
                    </div>
                </div>
                <p class="wa-preview-note" x-show="editor.key === 'low_stock'">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8v.01"/></svg>
                    Goes to your team, not the customer.
                </p>
            </div>
        </div>

        <footer class="wa-editor-foot">
            <button type="button" class="wa-link wa-test" x-on:click="sendTest" x-show="! editor.readonly"
                    x-bind:disabled="editor.testing">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11.5 21 3l-8.5 18-2.2-7.3L3 11.5Z"/></svg>
                <span x-text="editor.testing ? 'Sending…' : 'Send test to me'"></span>
            </button>
            {{-- The test result, whatever it actually was. Announced. --}}
            <span class="wa-test-result" x-show="editor.testResult" role="status" aria-live="polite"
                  x-bind:class="editor.testOk ? 'is-ok' : 'is-bad'" x-text="editor.testResult"></span>

            <span class="wa-editor-foot-actions">
                <button type="button" class="wa-button" x-on:click="closeEditor"
                        x-text="editor.readonly ? 'Close' : 'Cancel'"></button>
                <button type="button" class="wa-button is-primary" x-on:click="saveEditor"
                        x-show="! editor.readonly" x-bind:disabled="editor.saving"
                        x-text="editor.saving ? 'Saving…' : 'Save'"></button>
            </span>
        </footer>
    </div>
</div>
