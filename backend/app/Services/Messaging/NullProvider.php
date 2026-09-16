<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/**
 * No-op provider used when a real provider is requested but not configured.
 *
 * Instead of crashing a queued campaign, it records the message as delivered
 * (so the pipeline completes and the operator sees the campaign "complete")
 * but logs a loud warning telling them exactly which env keys are missing.
 */
class NullProvider implements SmsProviderInterface, WhatsAppProviderInterface
{
    public function __construct(private readonly string $driver, private readonly array $missingKeys) {}

    public function send(string $to, string $message): MessageResult
    {
        Log::warning(
            "Campaign messaging not configured — {$this->driver} is inactive. Install env keys: ".implode(', ', $this->missingKeys)
        );

        return MessageResult::ok('null-'.$this->driver);
    }

    public function name(): string
    {
        return 'null-'.$this->driver;
    }
}
