<?php

namespace App\Services\Messaging;

interface WhatsAppProviderInterface
{
    public function send(string $to, string $message): MessageResult;

    public function name(): string;
}
