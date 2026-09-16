<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Taqnyat SMS (Saudi/Jordanian/GCC regional provider).
 * https://taqnyat.sa — JWT auth, single "recipients" number per call.
 *
 * Requires env:
 *   TAQNYAT_TOKEN, TAQNYAT_SENDER_NAME
 */
class TaqnyatSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $token,
        private readonly string $sender,
    ) {}

    public function send(string $to, string $message): MessageResult
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post('https://api.taqnyat.sa/v1/messages', [
                    'recipients' => $to,
                    'body' => $message,
                    'sender' => $this->sender,
                ])->withHeaders([
                    'Authorization' => 'Bearer '.$this->token,
                ]);

            if ($response->successful() && $response->json('status') !== false) {
                return MessageResult::ok((string) ($response->json('messageId') ?? $response->json('id')));
            }

            $error = (string) ($response->json('message') ?? $response->body());
            Log::warning('Taqnyat SMS failed', ['to' => $to, 'error' => $error, 'status' => $response->status()]);

            return MessageResult::fail('Taqnyat: '.$error);
        } catch (\Throwable $e) {
            Log::warning('Taqnyat SMS exception', ['to' => $to, 'error' => $e->getMessage()]);

            return MessageResult::fail('Taqnyat: '.$e->getMessage());
        }
    }

    public function name(): string
    {
        return 'taqnyat';
    }
}
