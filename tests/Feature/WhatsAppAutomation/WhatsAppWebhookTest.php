<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Models\Customer;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Meta webhook endpoint contract.
 *
 * This is the one route Inventra exposes to the public internet without authentication, so its
 * guarantees are structural rather than incidental:
 *
 *   - the subscription challenge is answered only for the configured token, compared timing-safely,
 *     and never when no token is configured;
 *   - every event body is authenticated by HMAC over the RAW bytes before it is parsed, so a
 *     payload that fails the signature is never interpreted at all;
 *   - status progression is monotonic, so Meta's retries, duplicates and out-of-order deliveries
 *     are no-ops rather than corruption.
 *
 * Credentials here are local test fixtures injected through config; no real Meta secret is used.
 *
 * Sender isolation and the send-pipeline's own forward-only behaviour are covered by
 * WhatsAppConnectionIsolationTest; this file covers the endpoint itself.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Local fixtures. Never a real Meta credential. */
    private const APP_SECRET = 'webhook-test-app-secret';

    private const VERIFY_TOKEN = 'webhook-test-verify-token';

    private const SENDER = 'PHONE_NUMBER_ID_A';

    /** The WhatsApp Business Account the test sender belongs to; Meta reports it as `entry.id`. */
    private const WABA = '100000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        config([
            'whatsapp.app_secret' => self::APP_SECRET,
            'whatsapp.verify_token' => self::VERIFY_TOKEN,
        ]);
    }

    // ── Verification (GET) ──────────────────────────────────────────────────────────────────────

    /**
     * Meta sends `hub.mode`, `hub.verify_token` and `hub.challenge`.
     *
     * PHP rewrites dots in query keys to underscores, which is why the handler reads `hub_mode`.
     * The request below uses Meta's real dotted spelling so that translation is exercised rather
     * than assumed — a handler reading the wrong names would fail Meta's "Verify and save".
     */
    public function test_a_correct_subscription_challenge_is_echoed_verbatim(): void
    {
        $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.self::VERIFY_TOKEN
            .'&hub.challenge=1158201444');

        $response->assertOk();
        // Echoed exactly, as plain text: Meta compares the body byte for byte.
        $this->assertSame('1158201444', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_challenge_containing_only_digits_is_not_coerced_to_a_number(): void
    {
        $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.self::VERIFY_TOKEN
            .'&hub.challenge=0071234');

        // A leading zero must survive: the response is the challenge string, not an integer.
        $this->assertSame('0071234', $response->assertOk()->getContent());
    }

    public function test_an_incorrect_verify_token_is_refused(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')
            ->assertForbidden();
    }

    /**
     * A near-miss token is refused.
     *
     * `hash_equals` is a timing-safe comparison, not a loose one; a token sharing a prefix with the
     * configured value must be rejected exactly like any other wrong token.
     */
    public function test_a_token_sharing_a_prefix_is_still_refused(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.substr(self::VERIFY_TOKEN, 0, -1)
            .'&hub.challenge=123')
            ->assertForbidden();
    }

    /**
     * An unconfigured verify token refuses everything, including an empty submitted token.
     *
     * This is the fail-closed case that matters in production: on a server where
     * WHATSAPP_VERIFY_TOKEN has not been set, an empty configured value must never accidentally
     * match an empty supplied value and hand a subscription to whoever asked for it.
     */
    #[DataProvider('unconfiguredTokens')]
    public function test_an_unconfigured_verify_token_refuses_every_challenge(mixed $configured): void
    {
        config(['whatsapp.verify_token' => $configured]);

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')
            ->assertForbidden();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=123')
            ->assertForbidden();
    }

    /** @return array<string, array{mixed}> */
    public static function unconfiguredTokens(): array
    {
        return ['empty string' => [''], 'null' => [null]];
    }

    /** @return array<string, array{string}> */
    public static function malformedChallenges(): array
    {
        return [
            'wrong mode' => ['hub.mode=unsubscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=123'],
            'missing mode' => ['hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=123'],
            'missing token' => ['hub.mode=subscribe&hub.challenge=123'],
            'missing challenge' => ['hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN],
            'array-shaped token' => ['hub.mode=subscribe&hub.verify_token[]='.self::VERIFY_TOKEN.'&hub.challenge=123'],
            'array-shaped challenge' => ['hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge[]=123'],
        ];
    }

    /** Anything that is not a well-formed subscribe challenge is refused rather than guessed at. */
    #[DataProvider('malformedChallenges')]
    public function test_a_malformed_challenge_is_refused(string $query): void
    {
        $this->get('/webhooks/whatsapp?'.$query)->assertForbidden();
    }

    /** The endpoint is public: verification happens before any session exists. */
    public function test_verification_requires_no_authentication(): void
    {
        $this->assertGuest();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=abc')
            ->assertOk();
    }

    // ── Signature (POST) ────────────────────────────────────────────────────────────────────────

    public function test_a_correctly_signed_event_is_accepted(): void
    {
        $message = $this->sentMessage('wamid.OK');

        $this->event([$this->statusEntry('wamid.OK', 'delivered')])
            ->assertOk()
            ->assertSee('EVENT_RECEIVED');

        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->refresh()->status);
    }

    /** @return array<string, array{?string}> */
    public static function badSignatures(): array
    {
        return [
            'missing header' => [null],
            'empty header' => [''],
            'no sha256 prefix' => ['deadbeef'],
            'wrong algorithm prefix' => ['sha1=deadbeef'],
            'wrong digest' => ['sha256='.str_repeat('a', 64)],
        ];
    }

    /**
     * An unsigned or wrongly signed body is refused, and — critically — never applied.
     *
     * The status assertion is the point: rejection must happen before the payload is parsed, so a
     * forged event cannot move a real message even though its contents are otherwise valid.
     */
    #[DataProvider('badSignatures')]
    public function test_an_unsigned_or_wrongly_signed_event_is_refused_and_never_applied(?string $signature): void
    {
        $message = $this->sentMessage('wamid.FORGED');

        $this->event([$this->statusEntry('wamid.FORGED', 'delivered')], signature: $signature, sign: false)
            ->assertForbidden();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
        $this->assertNull($message->refresh()->delivered_at);
    }

    /** A signature computed over different bytes than were sent is refused. */
    public function test_a_signature_over_a_different_body_is_refused(): void
    {
        $message = $this->sentMessage('wamid.TAMPER');

        $sent = json_encode(['entry' => [['id' => self::WABA, 'changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::SENDER],
            'statuses' => [$this->statusEntry('wamid.TAMPER', 'delivered')],
        ]]]]]]);
        // Signed over a body that was never transmitted.
        $signature = 'sha256='.hash_hmac('sha256', $sent.' ', self::APP_SECRET);

        $this->postRaw($sent, $signature)->assertForbidden();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    /**
     * With no app secret configured the endpoint refuses every event.
     *
     * Fail-closed: an unconfigured server must reject traffic it cannot authenticate rather than
     * trusting it.
     */
    public function test_an_unconfigured_app_secret_refuses_every_event(): void
    {
        $message = $this->sentMessage('wamid.NOSECRET');
        config(['whatsapp.app_secret' => '']);

        $this->postRaw('{"entry":[]}', 'sha256='.hash_hmac('sha256', '{"entry":[]}', ''))
            ->assertForbidden();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    // ── Status progression ──────────────────────────────────────────────────────────────────────

    public function test_a_message_progresses_sent_then_delivered_then_read(): void
    {
        $message = $this->sentMessage('wamid.PROGRESS');

        $this->event([$this->statusEntry('wamid.PROGRESS', 'delivered')])->assertOk();
        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNull($message->read_at);

        $this->event([$this->statusEntry('wamid.PROGRESS', 'read')])->assertOk();
        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->status);
        $this->assertNotNull($message->read_at);
        // Earlier evidence is kept, not overwritten.
        $this->assertNotNull($message->delivered_at);
    }

    /** A queued message accepts `sent` and stamps the time it left. */
    public function test_a_queued_message_accepts_a_sent_event(): void
    {
        $message = $this->message('wamid.QUEUED', WhatsAppMessage::STATUS_QUEUED);

        $this->event([$this->statusEntry('wamid.QUEUED', 'sent')])->assertOk();

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->sent_at);
    }

    public function test_a_failed_event_records_the_provider_reason(): void
    {
        $message = $this->sentMessage('wamid.FAIL');

        $this->event([$this->statusEntry('wamid.FAIL', 'failed') + [
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]])->assertOk();

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('131047', $message->failure_code);
        $this->assertSame('Re-engagement message', $message->failure_reason);
    }

    /** Failure is terminal: nothing outranks it, so a late success cannot mask it. */
    public function test_a_failed_message_cannot_be_walked_forward_by_a_later_event(): void
    {
        $message = $this->sentMessage('wamid.TERMINAL');

        $this->event([$this->statusEntry('wamid.TERMINAL', 'failed')])->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->refresh()->status);

        foreach (['delivered', 'read', 'sent'] as $later) {
            $this->event([$this->statusEntry('wamid.TERMINAL', $later)])->assertOk();
            $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->refresh()->status,
                "a {$later} event must not clear a terminal failure");
        }
    }

    // ── Idempotency and replay ──────────────────────────────────────────────────────────────────

    /**
     * Meta retries until it receives a 200, so the same event arrives repeatedly.
     *
     * Re-delivering it must change nothing the second time — including the recorded timestamp,
     * which would otherwise drift forward on every retry and misreport when delivery happened.
     */
    public function test_a_duplicated_event_changes_nothing_the_second_time(): void
    {
        $message = $this->sentMessage('wamid.DUP');

        $this->event([$this->statusEntry('wamid.DUP', 'delivered', 1_760_000_000)])->assertOk();
        $first = $message->refresh()->only(['status', 'delivered_at', 'sent_at', 'read_at', 'failed_at']);

        foreach (range(1, 3) as $ignored) {
            $this->event([$this->statusEntry('wamid.DUP', 'delivered', 1_760_000_099)])->assertOk();
        }

        $this->assertEquals($first, $message->refresh()->only(['status', 'delivered_at', 'sent_at', 'read_at', 'failed_at']));
    }

    /**
     * Out-of-order delivery is normal: Meta does not guarantee ordering, so `read` can arrive
     * before `delivered`. The later-ranked status wins and the earlier one cannot walk it back.
     */
    public function test_an_out_of_order_event_cannot_regress_a_message(): void
    {
        $message = $this->sentMessage('wamid.ORDER');

        $this->event([$this->statusEntry('wamid.ORDER', 'read')])->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);

        $this->event([$this->statusEntry('wamid.ORDER', 'delivered')])->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);

        $this->event([$this->statusEntry('wamid.ORDER', 'sent')])->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);
    }

    /** Several statuses in one payload are all applied, in the order Meta sent them. */
    public function test_a_batched_payload_applies_every_status_it_carries(): void
    {
        $one = $this->sentMessage('wamid.BATCH1');
        $two = $this->sentMessage('wamid.BATCH2');

        $this->event([
            $this->statusEntry('wamid.BATCH1', 'delivered'),
            $this->statusEntry('wamid.BATCH2', 'read'),
        ])->assertOk();

        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $one->refresh()->status);
        $this->assertSame(WhatsAppMessage::STATUS_READ, $two->refresh()->status);
    }

    // ── Unknown and malformed content ───────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unknownStatuses(): array
    {
        return [
            'meta vocabulary we do not map' => ['deleted'],
            'future status' => ['warning'],
            'empty' => [''],
            'uppercase of a known status' => ['DELIVERED'],
        ];
    }

    /**
     * An unrecognised status is ignored, never guessed at.
     *
     * Mapping an unknown word onto the nearest known one would let Inventra assert a delivery Meta
     * never reported. Note `DELIVERED` is included: the match is exact, and a case variant is not
     * a status we recognise.
     */
    #[DataProvider('unknownStatuses')]
    public function test_an_unknown_status_is_ignored(string $status): void
    {
        $message = $this->sentMessage('wamid.UNKNOWN');

        $this->event([$this->statusEntry('wamid.UNKNOWN', $status)])->assertOk();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    /** An event for a provider id Inventra never issued is acknowledged and dropped. */
    public function test_an_event_for_an_unknown_message_is_acknowledged_without_effect(): void
    {
        $message = $this->sentMessage('wamid.MINE');

        $this->event([$this->statusEntry('wamid.SOMEONE_ELSE', 'delivered')])->assertOk();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function malformedBodies(): array
    {
        return [
            'not json' => ['this is not json'],
            'truncated json' => ['{"entry": [{"changes":'],
            'empty body' => [''],
        ];
    }

    /** A body that is not JSON is reported as unprocessable, never a 500. */
    #[DataProvider('malformedBodies')]
    public function test_a_body_that_is_not_json_is_rejected_cleanly(string $body): void
    {
        $this->postRaw($body, 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET))
            ->assertStatus(422);
    }

    /** @return array<string, array{string}> */
    public static function unexpectedShapes(): array
    {
        return [
            'json scalar' => ['"just a string"'],
            'json number' => ['42'],
            'empty object' => ['{}'],
            'entry not a list' => ['{"entry": "nope"}'],
            'entry item not an array' => ['{"entry": ["nope"]}'],
            'changes not a list' => ['{"entry": [{"changes": "nope"}]}'],
            'statuses missing' => ['{"entry": [{"changes": [{"value": {}}]}]}'],
            'statuses not a list' => ['{"entry": [{"changes": [{"value": {"statuses": "nope"}}]}]}'],
            'status entry not an array' => ['{"entry": [{"changes": [{"value": {"statuses": ["nope"]}}]}]}'],
            'status id not a string' => ['{"entry": [{"changes": [{"value": {"statuses": [{"id": 1, "status": "read"}]}}]}]}'],
            'inbound message payload' => ['{"entry": [{"changes": [{"value": {"messages": [{"from": "234800", "text": {"body": "hi"}}]}}]}]}'],
        ];
    }

    /**
     * A structurally unexpected payload is absorbed without error and without effect.
     *
     * Meta sends field types Inventra does not subscribe to, and a malicious caller can send
     * anything at all. Both must be survivable: the endpoint answers 200 (so Meta does not retry
     * forever) or 422, and never 500, and never mutates a message.
     *
     * The inbound-message case documents current behaviour rather than asserting it is complete:
     * customer replies are not handled today, and are dropped silently.
     */
    #[DataProvider('unexpectedShapes')]
    public function test_an_unexpected_payload_shape_is_absorbed_safely(string $body): void
    {
        $message = $this->sentMessage('wamid.SHAPE');

        $response = $this->postRaw($body, 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET));

        $this->assertContains($response->getStatusCode(), [200, 422],
            'an unexpected shape must not produce a server error');
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    /** A provider timestamp far in the future is not trusted as the event time. */
    public function test_an_implausible_provider_timestamp_falls_back_to_now(): void
    {
        $message = $this->sentMessage('wamid.FUTURE');

        $this->event([$this->statusEntry('wamid.FUTURE', 'delivered', 99_999_999_999)])->assertOk();

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->status);
        $this->assertTrue($message->delivered_at->lessThanOrEqualTo(now()->addMinute()),
            'a far-future provider timestamp must not be stored');
    }

    // ── Transport ───────────────────────────────────────────────────────────────────────────────

    /** The endpoint is exempt from CSRF, or Meta could never post to it. */
    public function test_events_are_exempt_from_csrf_and_need_no_session(): void
    {
        $this->assertGuest();

        $this->sentMessage('wamid.CSRF');
        $this->event([$this->statusEntry('wamid.CSRF', 'delivered')])->assertOk();
    }

    /** Bodies beyond the configured ceiling are refused before parsing. */
    public function test_an_oversized_body_is_refused(): void
    {
        $body = '{"padding":"'.str_repeat('x', (int) config('whatsapp.webhook_max_bytes', 262144) + 100).'"}';

        $this->postRaw($body, 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET))
            ->assertStatus(413);
    }

    /** No secret reaches the response, on any path. */
    public function test_no_configured_secret_is_echoed_in_any_response(): void
    {
        $bodies = [
            $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->getContent(),
            $this->postRaw('{}', 'sha256=bad')->getContent(),
            $this->postRaw('{"entry":[]}', 'sha256='.hash_hmac('sha256', '{"entry":[]}', self::APP_SECRET))->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString(self::APP_SECRET, (string) $body);
            $this->assertStringNotContainsString(self::VERIFY_TOKEN, (string) $body);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    /**
     * One status entry in Meta's shape.
     *
     * @return array<string, mixed>
     */
    private function statusEntry(string $providerId, string $status, ?int $timestamp = null): array
    {
        return array_filter([
            'id' => $providerId,
            'status' => $status,
            'timestamp' => $timestamp === null ? null : (string) $timestamp,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Posts a signed event carrying the given statuses.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     */
    private function event(array $statuses, ?string $signature = null, bool $sign = true, string $sender = self::SENDER): TestResponse
    {
        $body = json_encode(['entry' => [['id' => self::WABA, 'changes' => [['value' => [
            'metadata' => ['phone_number_id' => $sender],
            'statuses' => $statuses,
        ]]]]]]);

        return $this->postRaw($body, $sign ? 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET) : $signature);
    }

    /** Posts a raw body with an explicit signature header, bypassing Laravel's JSON helpers. */
    private function postRaw(string $body, ?string $signature): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $headers['HTTP_X-Hub-Signature-256'] = $signature;
        }

        return $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], $headers, $body);
    }

    private function sentMessage(string $providerId): WhatsAppMessage
    {
        return $this->message($providerId, WhatsAppMessage::STATUS_SENT);
    }

    /** A persisted message in a known state, addressed from the test sender. */
    private function message(string $providerId, string $status): WhatsAppMessage
    {
        static $sequence = 0;
        $sequence++;

        $connection = WhatsAppConnection::query()->firstOrCreate(
            ['singleton_key' => 'whatsapp'],
            ['provider' => 'fake', 'status' => 'disconnected'],
        );
        $connection->forceFill([
            'waba_id' => self::WABA,
            'phone_number_id' => self::SENDER,
            'display_phone_number' => '+234 700 000 1234',
            'access_token' => 'local-test-token',
            'status' => 'connected',
            'verified_at' => now(),
            'connected_at' => now(),
        ])->save();

        $customer = Customer::factory()->create([
            'phone' => '+2348090'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'is_active' => true,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
        ]);

        $message = new WhatsAppMessage;
        $message->business_id = $connection->business_id;
        $message->whatsapp_automation_id = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME)?->id;
        $message->whatsapp_connection_id = $connection->id;
        $message->type = WhatsAppAutomation::WELCOME;
        $message->customer_id = $customer->id;
        $message->recipient_name = $customer->full_name;
        $message->destination_phone = $customer->phone;
        $message->body = 'Hello';
        $message->idempotency_key = 'webhook-test:'.$providerId;
        $message->origin = 'automatic';
        $message->provider = 'fake';
        $message->sender_phone_number_id = self::SENDER;
        $message->provider_message_id = $providerId;
        $message->status = $status;
        $message->queued_at = now();
        $message->sent_at = $status === WhatsAppMessage::STATUS_QUEUED ? null : now();
        $message->attempt = 1;
        $message->save();

        return $message;
    }
}
