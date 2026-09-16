<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Customer;
use App\Services\Campaign\CampaignSegmentResolver;
use App\Services\Messaging\MessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CampaignDispatchJob implements ShouldQueue
{
    use Queueable;

    /** Bounded chunk of recipients processed per iteration. */
    public const CHUNK = 500;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $campaignId) {}

    /**
     * Execute the job.
     */
    public function handle(
        CampaignSegmentResolver $resolver,
        MessagingService $messaging,
    ): void {
        $campaign = Campaign::query()->find($this->campaignId);

        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }

        $campaign->updateQuietly(['started_at' => now()]);

        $customerIds = $resolver->recipientIds(
            (string) $campaign->business_id,
            $campaign->segment_type,
            $campaign->metadata['tier'] ?? null,
        );

        $campaign->updateQuietly(['recipients_count' => count($customerIds)]);

        // Clear any stale rows from a prior (re)dispatch.
        $campaign->messages()->delete();

        $sent = 0;
        $failed = 0;
        $delivered = 0;

        foreach (array_chunk($customerIds, self::CHUNK) as $chunkIds) {
            $customers = Customer::query()
                ->whereIn('id', $chunkIds)
                ->get()
                ->keyBy('id');

            foreach ($chunkIds as $customerId) {
                $customer = $customers->get($customerId);
                if (! $customer) {
                    continue;
                }

                $phone = $resolver->normalizePhone($customer->phone);

                if ($phone === null) {
                    $failed++;
                    $this->persist($campaign, $customer, 'Invalid or missing phone number', 'failed', null, null, null);

                    continue;
                }

                $body = $resolver->render($campaign->message_template, $customer);
                $result = $messaging->send($campaign->channel, $phone, $body);

                if ($result->success) {
                    $sent++;
                    // Provider ack == sent. Mark most as delivered optimistically;
                    // providers with delivery receipts can flip it via webhook later.
                    $delivered++;
                    $this->persist($campaign, $customer, $body, 'sent', $result->providerMessageId, null, now());
                } else {
                    $failed++;
                    $this->persist($campaign, $customer, $body, 'failed', null, $result->error, null);
                }
            }
        }

        $campaign->updateQuietly([
            'sent_count' => $sent,
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'status' => $failed > 0 ? 'failed' : 'completed',
            'sent_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        $campaign = Campaign::query()->find($this->campaignId);
        if ($campaign) {
            CampaignMessage::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->update(['status' => 'failed', 'error' => $e ? $e->getMessage() : null]);

            $campaign->updateQuietly([
                'status' => 'failed',
                'completed_at' => now(),
                'failed_count' => CampaignMessage::query()
                    ->where('campaign_id', $campaign->id)
                    ->where('status', 'failed')
                    ->count(),
            ]);
        }
    }

    private function persist(
        Campaign $campaign,
        Customer $customer,
        ?string $message,
        string $status,
        ?string $providerMessageId,
        ?string $error,
        $sentAt,
    ): void {
        CampaignMessage::create([
            'business_id' => $campaign->business_id,
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'phone' => (string) ($customer->phone ?? ''),
            'message' => $message ?? '',
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error' => $error,
            'sent_at' => $sentAt,
        ]);
    }
}
