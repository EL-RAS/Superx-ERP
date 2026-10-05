<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Single database notification type for the whole application.
 *
 * Every alert is discriminated by `tag` (stored inside `data`) rather than by
 * PHP class, so one class can serve welcome messages, stock alerts, expiry
 * warnings, purchase orders and invoices without a handful of near-identical
 * subclasses. `tag` is unique per subject (e.g. `stock_low:product:5`), which
 * is what the service uses to de-duplicate repeat alerts.
 *
 * Bilingual payloads are stored as `{en, ar}` objects so one row serves both
 * locales — the API layer picks the right side at read time.
 */
class AppNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, string>  $title  ['en' => ..., 'ar' => ...]
     * @param  array<string, string>  $message  ['en' => ..., 'ar' => ...]
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $severity,
        public readonly array $title,
        public readonly array $message,
        public readonly ?string $actionUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tag' => $this->tag,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
        ];
    }
}
