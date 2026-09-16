<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UltraMsg WhatsApp unofficial gateway. https://docs.ultramsg.com
 *
 * Requires env:
 *   ULTRAMSG_INSTANCE_ID, ULTRAMSG_TOKEN
 */
class UltraMsgWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        private readonly string $instanceId,
        private readonly string $token,
    ) {}

    public function send(string $to, string $message): MessageResult
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.ultramsg.com/{$this->instanceId}/messages/chat", [
                    'token' => $this->token,
                    'to' => $to,
                    'body' => $message,
                ]);

            if ($response->successful() && $response->json('status') === 'success') {
                return MessageResult::ok((string) ($response->json('id') ?? $response->json('messageId')));
            }

            $error = (string) ($response->json('description') ?? $response->body());
            Log::warning('UltraMsg failed', ['to' => $to, 'error' => $error, 'status' => $response->status()]);

            return MessageResult::fail('UltraMsg: '.$error);
        } catch (\Throwable $e) {
            Log::warning('UltraMsg exception', ['to' => $to, 'error' => $e->getMessage()]);

            return MessageResult::fail('UltraMsg: '.$e->getMessage());
        }
    }

    public function name(): string
    {
        return 'ultramsg';
    }
}
