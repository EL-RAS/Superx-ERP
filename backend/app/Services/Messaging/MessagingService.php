<?php

namespace App\Services\Messaging;

/**
 * Builds the SMS / WhatsApp providers selected by env config.
 *
 * If a provider is selected but its required keys are missing, returns a
 * NullProvider so the dispatch pipeline never throws.
 */
class MessagingService
{
    public function smsProvider(): SmsProviderInterface
    {
        $provider = (string) config('messaging.sms_provider', 'null');

        return match ($provider) {
            'twilio' => $this->buildTwilio(),
            'taqnyat' => $this->buildTaqnyat(),
            default => new NullProvider('sms', ['SMS_PROVIDER', 'TWILIO_SID/TWILIO_AUTH_TOKEN/TWILIO_SMS_NUMBER or TAQNYAT_TOKEN/TAQNYAT_SENDER_NAME']),
        };
    }

    public function whatsappProvider(): WhatsAppProviderInterface
    {
        $provider = (string) config('messaging.whatsapp_provider', 'null');

        return match ($provider) {
            'bus' => $this->buildWhatsAppBus(),
            'ultramsg' => $this->buildUltraMsg(),
            default => new NullProvider('whatsapp', ['WHATSAPP_PROVIDER', 'WHATSAPP_TOKEN/WHATSAPP_PHONE_NUMBER_ID or ULTRAMSG_INSTANCE_ID/ULTRAMSG_TOKEN']),
        };
    }

    /** Send a single message on the configured channel. */
    public function send(string $channel, string $to, string $message): MessageResult
    {
        return $channel === 'whatsapp'
            ? $this->whatsappProvider()->send($to, $message)
            : $this->smsProvider()->send($to, $message);
    }

    public function channelName(string $channel): string
    {
        return $channel === 'whatsapp'
            ? $this->whatsappProvider()->name()
            : $this->smsProvider()->name();
    }

    private function buildTwilio(): SmsProviderInterface
    {
        $sid = (string) config('messaging.twilio_sid');
        $token = (string) config('messaging.twilio_auth_token');
        $from = (string) config('messaging.twilio_sms_number');

        if ($sid === '' || $token === '' || $from === '') {
            return new NullProvider('sms (twilio requested)', ['TWILIO_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_SMS_NUMBER']);
        }

        return new TwilioSmsProvider($sid, $token, $from);
    }

    private function buildTaqnyat(): SmsProviderInterface
    {
        $token = (string) config('messaging.taqnyat_token');
        $sender = (string) config('messaging.taqnyat_sender_name');

        if ($token === '' || $sender === '') {
            return new NullProvider('sms (taqnyat requested)', ['TAQNYAT_TOKEN', 'TAQNYAT_SENDER_NAME']);
        }

        return new TaqnyatSmsProvider($token, $sender);
    }

    private function buildWhatsAppBus(): WhatsAppProviderInterface
    {
        $token = (string) config('messaging.whatsapp_token');
        $phoneNumberId = (string) config('messaging.whatsapp_phone_number_id');

        if ($token === '' || $phoneNumberId === '') {
            return new NullProvider('whatsapp (bus requested)', ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID']);
        }

        return new WhatsAppBusProvider($token, $phoneNumberId);
    }

    private function buildUltraMsg(): WhatsAppProviderInterface
    {
        $instanceId = (string) config('messaging.ultramsg_instance_id');
        $token = (string) config('messaging.ultramsg_token');

        if ($instanceId === '' || $token === '') {
            return new NullProvider('whatsapp (ultramsg requested)', ['ULTRAMSG_INSTANCE_ID', 'ULTRAMSG_TOKEN']);
        }

        return new UltraMsgWhatsAppProvider($instanceId, $token);
    }
}
