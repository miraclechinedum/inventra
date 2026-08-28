<?php

namespace Tests\Feature\WhatsApp;

use App\Exceptions\WhatsAppOutcomeUnknownException;
use App\Models\WhatsAppDelivery;
use App\Services\MetaWhatsAppClient;
use App\Support\PhoneMask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWhatsAppClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.graph_version', 'v23.0');
        config()->set('whatsapp.phone_number_id', '123456789');
        config()->set('whatsapp.access_token', 'non-production-access-token');
        config()->set('whatsapp.app_secret', 'non-production-app-secret');
        config()->set('whatsapp.verify_token', 'non-production-verify-token');
        config()->set('whatsapp.receipt_template.name', 'inventra_receipt');
        config()->set('whatsapp.receipt_template.language', 'en');
    }

    public function test_client_sends_configured_template_with_bounded_https_endpoint(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.accepted']]], 200)]);
        $delivery = new WhatsAppDelivery;
        $delivery->destination_phone = '+2348012345678';
        $parameters = ['customer_name' => 'Ada', 'sale_number' => 'SALE-000001'];

        $result = app(MetaWhatsAppClient::class)->sendReceipt($delivery, $parameters);

        $this->assertTrue($result->accepted);
        $this->assertSame('wamid.accepted', $result->providerMessageId);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.com/v23.0/123456789/messages'
                && $request->hasHeader('Authorization', 'Bearer non-production-access-token')
                && $body['type'] === 'template'
                && $body['template']['name'] === 'inventra_receipt'
                && $body['template']['language']['code'] === 'en'
                && $body['to'] === '2348012345678';
        });
    }

    public function test_rejection_is_sanitized_and_malformed_success_is_ambiguous(): void
    {
        $delivery = new WhatsAppDelivery;
        $delivery->destination_phone = '+2348012345678';
        Http::fakeSequence()
            ->push(['error' => ['code' => 131000, 'message' => str_repeat('x', 700)]], 400)
            ->push(['messages' => []], 200);
        $result = app(MetaWhatsAppClient::class)->sendReceipt($delivery, []);
        $this->assertFalse($result->accepted);
        $this->assertSame('131000', $result->failureCode);
        $this->assertSame(500, mb_strlen($result->failureReason));

        $this->expectException(WhatsAppOutcomeUnknownException::class);
        app(MetaWhatsAppClient::class)->sendReceipt($delivery, []);
    }

    public function test_configuration_requires_template_language_and_valid_graph_version(): void
    {
        $client = app(MetaWhatsAppClient::class);
        $this->assertTrue($client->isConfigured());

        config()->set('whatsapp.receipt_template.language', '');
        $this->assertFalse($client->isConfigured());
        config()->set('whatsapp.receipt_template.language', 'en');

        foreach (['23.0', 'v23', 'v23.0/messages', 'v23.0?token=x'] as $version) {
            config()->set('whatsapp.graph_version', $version);
            $this->assertFalse($client->isConfigured());
        }

        config()->set('whatsapp.graph_version', 'v23.0');
        config()->set('whatsapp.phone_number_id', '../messages');
        $this->assertFalse($client->isConfigured());
    }

    public function test_phone_mask_reveals_only_canonical_nigerian_e164_values(): void
    {
        $this->assertSame('+234801***5678', PhoneMask::display('+2348012345678'));

        foreach (['', 'short', 'letters-only', '+234-801-234-5678', 'abc12345'] as $unexpected) {
            $this->assertSame('********', PhoneMask::display($unexpected));
        }
    }
}
