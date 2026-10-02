<?php

namespace App\Support\WhatsApp;

use App\Support\Money;

/**
 * The template engine. Deliberately not a template engine in the usual sense: there is no
 * evaluation here at all.
 *
 * A template body is plain text containing `{{token}}` placeholders. Rendering is a single
 * `strtr()` over an allowlisted map — no Blade compilation, no `eval`, no PHP, no callable, no
 * model or property access, and nothing that can reach beyond the values the caller supplies. A
 * token this class does not know stays unrendered and is refused at validation time, so a template
 * can never be saved referring to data that does not exist.
 *
 * WhatsApp message bodies are plain text, not HTML, so values are inserted verbatim rather than
 * HTML-escaped — escaping here would put `&amp;` in a real customer's message. The safety of the
 * on-screen preview is the view's job: Blade's `{{ }}` escapes it when it is rendered into the page.
 * What this class guarantees is that a value can never become *markup or code* in the first place:
 * it is only ever a string substituted into a string.
 */
final class WhatsAppTemplate
{
    /** WhatsApp's own limit for a text message body. */
    public const MAX_LENGTH = 1024;

    /**
     * Which variables each automation may use. This is the allowlist: anything outside the list for
     * a given key is rejected, so a low-stock template cannot reach for a customer's name and a
     * welcome template cannot reach for a sale total.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'welcome' => ['customer_name', 'business_name'],
        'post_purchase' => ['customer_name', 'business_name', 'sale_total', 'balance_due'],
        'pickup_reminder' => ['customer_name', 'business_name'],
        'low_stock' => ['product_name', 'stock_left', 'reorder_level'],
    ];

    /** Human labels for the editor's chips, in the order the Figma shows them. */
    private const LABELS = [
        'customer_name' => 'Customer name',
        'business_name' => 'Business name',
        'sale_total' => 'Sale total',
        'balance_due' => 'Balance due',
        'product_name' => 'Product name',
        'stock_left' => 'Stock left',
        'reorder_level' => 'Reorder level',
    ];

    /** @return list<string> */
    public static function allowedFor(string $key): array
    {
        return self::ALLOWED[$key] ?? [];
    }

    public static function label(string $token): string
    {
        return self::LABELS[$token] ?? $token;
    }

    /** @return array<string, string> token => label, for the editor's chip row */
    public static function chipsFor(string $key): array
    {
        $chips = [];

        foreach (self::allowedFor($key) as $token) {
            $chips[$token] = self::label($token);
        }

        return $chips;
    }

    /**
     * Every `{{token}}` occurring in a body, whether or not it is allowed. Used by validation to
     * reject unknown tokens, and it is deliberately permissive in what it *finds* so that a
     * malformed or hostile token is discovered rather than ignored.
     *
     * @return list<string>
     */
    public static function tokensIn(string $body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]{1,64})\s*\}\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Anything `{{ … }}` in this body that the automation may not use.
     *
     * Two kinds are reported. A well-formed token outside the allowlist (`{{sale_total}}` in a
     * welcome message) is named as-is. Anything else between double braces — `{{ 7 * 7 }}`,
     * `{{ $x }}`, `{{ config('app.key') }}` — is reported too, even though it could only ever
     * render as literal characters. Nothing here is evaluated, so none of it is dangerous; it is
     * refused because a template containing it would silently send those characters to a customer,
     * and someone writing it plainly expected it to do something.
     *
     * @return list<string>
     */
    public static function unknownTokensFor(string $key, string $body): array
    {
        $unknown = array_diff(self::tokensIn($body), self::allowedFor($key));

        // Any remaining `{{ … }}` that was not a plain identifier.
        if (preg_match_all('/\{\{(.*?)\}\}/s', $body, $matches)) {
            foreach ($matches[1] as $inner) {
                if (preg_match('/^\s*[a-zA-Z0-9_]{1,64}\s*$/D', $inner) !== 1) {
                    $unknown[] = trim(mb_substr($inner, 0, 40));
                }
            }
        }

        // Blade's raw-output form. It cannot execute here — nothing in this class evaluates
        // anything — but a template containing it would send those characters verbatim to a
        // customer, and whoever typed it expected otherwise. Refused for the same reason as above.
        if (preg_match_all('/\{!!(.*?)!!\}/s', $body, $raw)) {
            foreach ($raw[1] as $inner) {
                $unknown[] = trim(mb_substr($inner, 0, 40));
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Substitutes allowed tokens with the supplied values.
     *
     * Only tokens in the allowlist for `$key` are substituted, and only from `$values`. A token with
     * no supplied value renders as an empty string rather than leaving `{{token}}` visible in a
     * customer's message — an incomplete sentence reads better than exposed machinery — and callers
     * are expected to have checked availability before sending.
     *
     * @param  array<string, string>  $values
     */
    public static function render(string $key, string $body, array $values): string
    {
        $map = [];

        foreach (self::allowedFor($key) as $token) {
            $map['{{'.$token.'}}'] = (string) ($values[$token] ?? '');
            // Tolerate incidental inner spacing the editor may produce.
            $map['{{ '.$token.' }}'] = $map['{{'.$token.'}}'];
        }

        return strtr($body, $map);
    }

    /**
     * The sample values the live preview uses. Never persisted and never sent to a customer: the
     * preview and the "Send test to me" action both render with these so an Administrator sees the
     * shape of the message without a real person receiving anything.
     *
     * @return array<string, string>
     */
    public static function sampleValues(string $businessName): array
    {
        return [
            'customer_name' => 'Emeka Obi',
            'business_name' => $businessName,
            // Money through the existing helper, so the preview shows the same formatting a real
            // message would. Decimal strings throughout; no floating-point arithmetic.
            'sale_total' => Money::format('9200.00'),
            'balance_due' => Money::format('0.00'),
            'product_name' => 'Brake pads (front)',
            'stock_left' => '2',
            'reorder_level' => '8',
        ];
    }
}
