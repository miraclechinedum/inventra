<?php

namespace App\Http\Requests\Settings;

use App\Models\BusinessSetting;
use App\Settings\BusinessSettings;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBusinessSettingsRequest extends FormRequest
{
    private bool $phoneWasInvalid = false;

    private bool $alertNumberWasInvalid = false;

    public function authorize(): bool
    {
        return $this->user()?->can('update', app(BusinessSettings::class)->current()) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (BusinessSetting::EDITABLE as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed = trim($value);
                $this->merge([$field => $field === 'business_name' ? $trimmed : ($trimmed === '' ? null : $trimmed)]);
            }
        }

        // The business phone is display-only contact detail, not an identity or a delivery
        // destination, so it borrows the project's Nigerian normalisation but none of the
        // Customer rules around uniqueness, consent or WhatsApp eligibility.
        $phone = $this->input('business_phone');

        if (is_string($phone) && $phone !== '') {
            $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);
            $this->phoneWasInvalid = $canonical === null;
            $this->merge(['business_phone' => $canonical ?? $phone]);
        }

        // The manager alert number IS a delivery destination — low-stock alerts are sent to it — so
        // it must be stored in exactly the canonical form the sender uses. Same normaliser as every
        // other Inventra phone; a blank field clears the setting rather than storing ''.
        $alert = $this->input('manager_alert_number');

        if (is_string($alert)) {
            $trimmed = trim($alert);
            $canonical = $trimmed === '' ? null : CanonicalLoginIdentifier::normalizeNigerianPhone($trimmed);
            $this->alertNumberWasInvalid = $trimmed !== '' && $canonical === null;
            $this->merge(['manager_alert_number' => $canonical]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:1', 'max:150'],
            'business_phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->phoneWasInvalid) {
                    $fail('Enter a valid Nigerian phone number.');
                }
            }],
            'business_address' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],

            // The destination for low-stock WhatsApp alerts. Already canonicalised above, so the
            // stored value is byte-identical to what the sender will dial.
            //
            // The "could not be normalised" case is reported in withValidator(), NOT by a closure
            // here: normalisation merges null for a number it could not parse, and `nullable` then
            // short-circuits the rest of this chain, so a closure would never run for exactly the
            // inputs it exists to reject.
            'manager_alert_number' => ['nullable', 'string', 'max:17'],

            // Profile metadata. Both are allowlisted rather than free text, so a crafted request
            // cannot store a value the screen would then render or the formatter would not know.
            'business_type' => ['nullable', 'string', Rule::in(BusinessSetting::TYPES)],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys(BusinessSetting::CURRENCIES))],
            'tax_number' => ['nullable', 'string', 'max:40'],

            // Identity, bookkeeping and fixed-by-design columns are unreachable by construction;
            // rejecting them explicitly turns a silent no-op into a visible validation error.
            // `logo_path` is among them: the logo is set by its own upload action, which writes a
            // path it generated, so a posted value could otherwise point the record at any file.
            'id' => ['prohibited'], 'business_id' => ['prohibited'], 'singleton_key' => ['prohibited'],
            'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'updated_by' => ['prohibited'],
            'logo_path' => ['prohibited'], 'currency_symbol' => ['prohibited'],

            // Removed from the Business profile screen but NOT from the database: these columns
            // still hold real data. Prohibited rather than merely unlisted so a stale cached form
            // or a hand-crafted request cannot overwrite values no screen is responsible for any
            // more — an unlisted key would be ignored quietly, which is how data gets lost.
            'business_email' => ['prohibited'], 'city' => ['prohibited'], 'state' => ['prohibited'],

            // `currency_code` is not a column; it is the near-miss name for `currency` that a
            // stale form or a hand-written request is most likely to carry. Rejecting it turns a
            // silently ignored key into a visible error, so nobody believes they changed the
            // currency when they did not.
            'currency_code' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }

    /**
     * Reports an alert number that could not be normalised.
     *
     * Runs here rather than as a rule on the field because normalisation merges null for an
     * unparseable number, and `nullable` would then stop the chain before any closure on that field
     * could object — silently turning "that is not a valid number" into "the operator cleared it".
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->alertNumberWasInvalid) {
                $validator->errors()->add('manager_alert_number', 'Enter a valid Nigerian phone number.');
            }
        });
    }
}
