<?php

namespace App\Services;

use App\Contracts\WhatsAppClient;
use App\Exceptions\WhatsAppOutcomeUnknownException;
use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MetaWhatsAppClient implements WhatsAppClient
{
    public function isConfigured(): bool
    {
        foreach (['graph_version', 'phone_number_id', 'access_token', 'app_secret', 'verify_token', 'receipt_template.name', 'receipt_template.language'] as $key) {
            if (! is_string(config('whatsapp.'.$key)) || trim(config('whatsapp.'.$key)) === '') {
                return false;
            }
        }

        return preg_match('/^v\d+\.\d+$/D', config('whatsapp.graph_version')) === 1
            && ctype_digit(config('whatsapp.phone_number_id'));
    }

    public function sendReceipt(WhatsAppDelivery $delivery, array $parameters): WhatsAppSendResult
    {
        try {
            $response = Http::baseUrl('https://graph.facebook.com/'.config('whatsapp.graph_version'))
                ->withToken(config('whatsapp.access_token'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout((int) config('whatsapp.connect_timeout', 3))
                ->timeout((int) config('whatsapp.timeout', 10))
                ->withOptions(['allow_redirects' => false])
                ->post(config('whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($delivery->destination_phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => config('whatsapp.receipt_template.name'),
                        'language' => ['code' => config('whatsapp.receipt_template.language')],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(
                                fn (string $value): array => ['type' => 'text', 'text' => $value],
                                array_values($parameters),
                            ),
                        ]],
                    ],
                ]);
        } catch (ConnectionException) {
            throw new WhatsAppOutcomeUnknownException('The provider outcome is unknown.');
        }

        if (! $response->successful()) {
            $code = data_get($response->json(), 'error.code');
            $message = data_get($response->json(), 'error.message');

            return WhatsAppSendResult::rejected(
                is_scalar($code) ? mb_substr((string) $code, 0, 64) : 'provider_rejected',
                is_string($message) ? mb_substr($message, 0, 500) : 'The provider rejected the message.',
            );
        }

        $messageId = data_get($response->json(), 'messages.0.id');

        if (! is_string($messageId) || $messageId === '' || mb_strlen($messageId) > 255) {
            throw new WhatsAppOutcomeUnknownException('The provider response could not be reconciled.');
        }

        return WhatsAppSendResult::accepted($messageId);
    }
}
