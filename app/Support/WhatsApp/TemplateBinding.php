<?php

namespace App\Support\WhatsApp;

use App\Models\WhatsAppAutomation;
use App\Tenancy\CurrentBusiness;
use LogicException;

/**
 * Whether an automation may actually be sent through Meta, and with what.
 *
 * Meta accepts a business-initiated message only as an APPROVED template, so an automation needs a
 * mapped template name, a language, and Meta's own APPROVED status before anything may go out. This
 * class is the single place that decides it, so no send path can reach a different conclusion.
 *
 * `variables` is the ordered list of allowlisted Inventra tokens that become Meta's positional body
 * parameters. It falls back to the automation's own allowlist order, which keeps a sensible default
 * while letting an operator map explicitly once the approved template's parameter order is known.
 */
final class TemplateBinding
{
    /** Meta's own statuses, mirrored. Only APPROVED permits a production send. */
    public const APPROVED = 'APPROVED';

    private function __construct(
        public readonly bool $sendable,
        public readonly ?string $name = null,
        public readonly string $language = 'en',
        /** @var list<string> */
        public readonly array $variables = [],
        public readonly ?string $reason = null,
    ) {}

    public static function for(WhatsAppAutomation $automation): self
    {
        // A binding is its own Business's template state. Callers load the automation through the
        // tenant scope; this refuses one that reached them any other way.
        $current = app(CurrentBusiness::class);

        if ($current->has() && (int) $automation->business_id !== $current->id()) {
            throw new LogicException('A template binding can only be read for the business in context.');
        }

        $name = $automation->template_name;

        if (! is_string($name) || trim($name) === '') {
            return new self(false, reason: 'No Meta template has been mapped to this automation yet.');
        }

        if ($automation->template_status !== self::APPROVED) {
            return new self(false, reason: 'The selected message template is not approved yet.');
        }

        $mapped = $automation->template_variables;
        $variables = is_array($mapped) && $mapped !== []
            ? array_values(array_filter($mapped, 'is_string'))
            : WhatsAppTemplate::allowedFor($automation->key);

        // Nothing outside the automation's own allowlist may ever become a template parameter.
        $allowed = WhatsAppTemplate::allowedFor($automation->key);
        $variables = array_values(array_intersect($variables, $allowed));

        return new self(
            sendable: true,
            name: trim($name),
            language: is_string($automation->template_language) && $automation->template_language !== ''
                ? $automation->template_language : 'en',
            variables: $variables,
        );
    }

    /**
     * The ordered body parameters for this send.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function parameters(array $values): array
    {
        return array_map(
            // Meta rejects newlines and tabs in body parameters, and an empty parameter too.
            fn (string $token): string => trim(preg_replace('/\s+/u', ' ', (string) ($values[$token] ?? ''))) ?: '-',
            $this->variables,
        );
    }
}
