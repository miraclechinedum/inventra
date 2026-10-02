<?php

namespace App\Support;

use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The human-visible reference for a Sale — the code printed on a receipt and quoted over the phone.
 *
 * It used to be derived from the primary key (SALE-000002), which made every sale's reference
 * guessable from any other and advertised how many the business had made. It is now random.
 *
 * Three identifiers, three jobs, and none substitutes for another:
 *
 *   sales.id          the internal key. Every foreign key, join and report. Never public.
 *   sales.public_id   the ULID in the URL. Unguessable, and what route binding resolves.
 *   sales.sale_number this. Short enough to read aloud, random enough not to be guessed.
 *
 * A reference is not an access control — a Sale is still protected by SalePolicy — but a short
 * random code stops a printed receipt from disclosing the shape of the business.
 *
 * Numbers issued before this generator existed — the SALE-000001 series — are left exactly as they
 * were recorded. They are what is printed on receipts still in customers' hands, and rewriting
 * history to tidy up a format would make those receipts unverifiable. Only new sales are numbered
 * this way.
 */
final class SaleNumber
{
    /**
     * An explicit display alphabet, chosen for two properties at once.
     *
     * The letters and digits deliberately exclude O, 0, I, 1 and L. These codes get read off
     * printed receipts and typed back in over the phone, and those five characters are the ones
     * people confuse.
     *
     * The four symbols — @ ! % # — widen the space without widening the risk. Every character that
     * has to be escaped somewhere a sale number is written is absent by construction: no <, >, &,
     * or quote (HTML); no comma, quote or newline (CSV); no =, + or - in the random portion, which
     * is what a spreadsheet reads as a formula; no /, \, ?, & or = (URL); no : or ; (log parsing).
     * A sale number is therefore safe to print, export and quote without any context-specific
     * treatment — though Blade still escapes it on the way out, and the CSV writer still quotes it.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789@!%#';

    private const LENGTH = 8;

    private const PREFIX = 'S-';

    /** How many times to retry on the vanishingly unlikely event of a collision. */
    private const ATTEMPTS = 12;

    /**
     * A fresh, unused reference.
     *
     * `random_int` rather than `rand` or `mt_rand`: this is drawn from the cryptographic source, so
     * the sequence cannot be predicted from previously issued codes, and nothing here derives from
     * a timestamp, a primary key or a counter. With 35 characters over 8 positions there are ~2.25
     * *trillion* possibilities, so a collision is remote — but the database's UNIQUE index is the
     * real guarantee, and this checks before returning so the insert does not have to fail.
     *
     * The retry count is bounded and the failure is explicit: if a dozen draws all collide, the
     * assumption behind this generator is wrong and a loud exception is the honest answer.
     */
    public static function generate(): string
    {
        // Numbers are unique within a Business, so only this Business's numbers can collide.
        $businessId = app(CurrentBusiness::class)->id();

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (! DB::table('sales')->where('business_id', $businessId)->where('sale_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate an unused sale number.');
    }

    private static function candidate(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return self::PREFIX.$code;
    }

    /**
     * The alphabet, for the tests that assert the format and for anything that needs to state the
     * contract without restating the string.
     */
    public static function alphabet(): string
    {
        return self::ALPHABET;
    }

    /** The shape of a sale number this generator issues. Historical numbers do not match it. */
    public static function pattern(): string
    {
        return '/^'.preg_quote(self::PREFIX, '/').'['.preg_quote(self::ALPHABET, '/').']{'.self::LENGTH.'}$/';
    }
}
