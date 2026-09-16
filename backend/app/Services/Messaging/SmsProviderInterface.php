<?php

namespace App\Services\Messaging;

interface SmsProviderInterface
{
    /**
     * Send a single SMS. Implementations must never throw raw exceptions;
     * return a MessageResult instead.
     */
    public function send(string $to, string $message): MessageResult;

    /** Human-readable provider name for reporting/logging. */
    public function name(): string;
}
