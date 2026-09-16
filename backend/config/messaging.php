<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Provider
    |--------------------------------------------------------------------------
    |
    | Choices: twilio | taqnyat | null
    |
    | When a real provider is selected but its keys are missing, the pipeline
    | falls back to the no-op "null" provider (records the send, logs a
    | warning) so queued campaigns never crash.
    |
    */
    'sms_provider' => env('SMS_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | SMS Credentials
    |--------------------------------------------------------------------------
    */
    'twilio_sid' => env('TWILIO_SID'),
    'twilio_auth_token' => env('TWILIO_AUTH_TOKEN'),
    'twilio_sms_number' => env('TWILIO_SMS_NUMBER'),

    'taqnyat_token' => env('TAQNYAT_TOKEN'),
    'taqnyat_sender_name' => env('TAQNYAT_SENDER_NAME'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider
    |--------------------------------------------------------------------------
    |
    | Choices: bus (Meta WhatsApp Business Cloud API) | ultramsg | null
    |
    */
    'whatsapp_provider' => env('WHATSAPP_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Credentials
    |--------------------------------------------------------------------------
    */
    'whatsapp_token' => env('WHATSAPP_TOKEN'),
    'whatsapp_phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'ultramsg_instance_id' => env('ULTRAMSG_INSTANCE_ID'),
    'ultramsg_token' => env('ULTRAMSG_TOKEN'),
];