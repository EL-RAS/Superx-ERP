<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API (Meta) — official WhatsApp channel.
 * https://developers.facebook.com/docs/whatsapp/cloud-api
 *
 * Requires env:
 *   WHATSAPP_TOKEN (system user access token), WHATSAPP_PHONE_NUMBER_ID
 */
class WhatsAppBusProvider implements WhatsAppProviderInterface
{
    private const GRAPH_VERSION = 'v21.0';

    public function __construct(
        private readonly string $token,
        private readonly string $phoneNumberId,
    ) {}

    public function send(string $to, string $message): MessageResult
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(15)
                ->asJson()
                ->post('https://graph.facebook.com/'.self::GRAPH_VERSION."/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $this->normalizeTo($to),
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]);

            if ($response->successful()) {
                return MessageResult::ok(
                    (string) ($response->json('messages.0.id') ?? $response->json('messages.0.wamid'))
                );
            }

            $error = (string) ($response->json('error.message') ?? $response->body());
            Log::warning('WhatsApp Bus failed', ['to' => $to, 'error' => $error, 'status' => $response->status()]);

            return MessageResult::fail('WhatsApp: '.$error);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp Bus exception', ['to' => $to, 'error' => $e->getMessage()]);

            return MessageResult::fail('WhatsApp: '.$e->getMessage());
        }
    }

    public function name(): string
    {
        return 'whatsapp-bus';
    }

    /** Cloud API wants a bare national number (`96278...`, no leading `+`). */
    private function normalizeTo(string $to): string
    {
        return ltrim($to, '+');
    }
}
