<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\CampaignDispatchJob;
use App\Models\Campaign;
use App\Services\Campaign\CampaignSegmentResolver;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::orderByDesc('created_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'segment_type' => 'required|string|in:lost,vip,all,tier',
            'tier' => 'nullable|string|in:bronze,silver,gold,platinum',
            'channel' => 'required|string|in:sms,whatsapp',
            'message_template' => 'required|string|max:1000',
        ]);

        $businessId = (string) $request->user()->business_id;
        $tier = $validated['tier'] ?? null;
        unset($validated['tier']);
        $validated['business_id'] = $businessId;
        $validated['status'] = 'draft';
        $validated['metadata'] = $tier !== null ? ['tier' => $tier] : [];

        $resolver = new CampaignSegmentResolver;
        $validated['recipients_count'] = $resolver->count(
            $businessId,
            $validated['segment_type'],
            $tier,
        );

        $campaign = Campaign::create($validated);

        return response()->json($campaign, 201);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->loadCount('messages');

        return response()->json($campaign);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        if ($campaign->status !== 'draft') {
            return response()->json(['message' => 'Only draft campaigns can be edited.'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'segment_type' => 'sometimes|string|in:lost,vip,all,tier',
            'tier' => 'nullable|string|in:bronze,silver,gold,platinum',
            'channel' => 'sometimes|string|in:sms,whatsapp',
            'message_template' => 'sometimes|string|max:1000',
        ]);

        $campaign->update($validated);

        // Recount when segment or channel changed.
        if (array_key_exists('segment_type', $validated) || array_key_exists('tier', $validated)) {
            $resolver = new CampaignSegmentResolver;
            $campaign->updateQuietly([
                'recipients_count' => $resolver->count(
                    (string) $campaign->business_id,
                    $campaign->segment_type,
                    $campaign->metadata['tier'] ?? null,
                ),
            ]);
        }

        return response()->json($campaign);
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === 'sending') {
            return response()->json(['message' => 'Cannot delete a campaign while it is sending.'], 422);
        }

        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted.']);
    }

    public function send(Request $request, Campaign $campaign): JsonResponse
    {
        if (! in_array($campaign->status, ['draft', 'failed'], true)) {
            return response()->json(['message' => 'Campaign cannot be sent in its current status.'], 422);
        }

        $campaign->updateQuietly(['status' => 'sending']);

        Bus::dispatchSync(new CampaignDispatchJob($campaign->id));

        return response()->json($campaign->fresh());
    }

    public function sendTest(Request $request, Campaign $campaign): JsonResponse
    {
        if (! in_array($campaign->status, ['draft', 'completed', 'failed', 'sending'], true)) {
            return response()->json(['message' => 'Campaign cannot be tested in its current status.'], 422);
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:40',
        ]);

        $messaging = app(MessagingService::class);
        $resolver = new CampaignSegmentResolver;

        $phone = $resolver->normalizePhone($validated['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'Invalid phone number.'], 422);
        }

        // Build a small test customer from the first real recipient (or the
        // caller's own name if the segment is empty).
        $firstRecipient = $resolver->recipients((string) $campaign->business_id, $campaign->segment_type)
            ->select(['id', 'name', 'phone'])
            ->first();

        if (! $firstRecipient) {
            $body = $campaign->message_template;
        } else {
            $body = $resolver->render($campaign->message_template, $firstRecipient);
        }

        $result = $messaging->send($campaign->channel, $phone, $body);

        return response()->json([
            'success' => $result->success,
            'sent_to' => $phone,
            'provider' => $result->success ? $messaging->channelName($campaign->channel) : null,
            'error' => $result->success ? null : $result->error,
        ], $result->success ? 200 : 422);
    }

    public function stats(Campaign $campaign): JsonResponse
    {
        $counts = DB::table('campaign_messages')
            ->where('campaign_id', $campaign->id)
            ->selectRaw(
                "COUNT(*) as total,
                 COUNT(*) FILTER (WHERE status = 'sent') as sent,
                 COUNT(*) FILTER (WHERE status = 'delivered') as delivered,
                 COUNT(*) FILTER (WHERE status = 'failed') as failed,
                 COUNT(*) FILTER (WHERE status = 'queued') as queued"
            )
            ->first();

        $providerBreakdown = DB::table('campaign_messages')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return response()->json([
            'campaign_id' => $campaign->id,
            'status' => $campaign->status,
            'recipients_count' => $campaign->recipients_count,
            'messages' => [
                'total' => (int) $counts->total,
                'sent' => (int) $counts->sent,
                'delivered' => (int) $counts->delivered,
                'failed' => (int) $counts->failed,
                'queued' => (int) $counts->queued,
            ],
            'status_breakdown' => $providerBreakdown,
            'started_at' => $campaign->started_at?->toISOString(),
            'completed_at' => $campaign->completed_at?->toISOString(),
            'sent_at' => $campaign->sent_at?->toISOString(),
        ]);
    }
}
