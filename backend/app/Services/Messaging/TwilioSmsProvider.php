<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio SMS via the REST API (no SDK required — plain HTTP form-encoded).
 *
 * Requires env:
 *   TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_SMS_NUMBER
 */
class TwilioSmsProvider implements SmsProviderInterface
{
    public function __construct(
        private readonly string $sid,
        private readonly string $token,
        private readonly string $from,
    ) {}

    public function send(string $to, string $message): MessageResult
    {
        try {
            $response = Http::withBasicAuth($this->sid, $this->token)
                ->asForm()
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                    'From' => $this->from,
                    'To' => $to,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return MessageResult::ok((string) $response->json('sid'));
            }

            $error = (string) ($response->json('message') ?? $response->body());
            Log::warning('Twilio SMS failed', ['to' => $to, 'error' => $error, 'status' => $response->status()]);

            return MessageResult::fail('Twilio: '.$error);
        } catch (\Throwable $e) {
            Log::warning('Twilio SMS exception', ['to' => $to, 'error' => $e->getMessage()]);

            return MessageResult::fail('Twilio: '.$e->getMessage());
        }
    }

    public function name(): string
    {
        return 'twilio';
    }
}
