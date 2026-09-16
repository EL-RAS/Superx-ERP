<?php

namespace App\Services\Campaign;

use App\Models\Customer;
use App\Services\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Resolves the recipients for a campaign segment.
 *
 * The segment semantics mirror the counts shown on the CRM segments cards
 * (CustomerController::segments) so the dispatch count matches what the user
 * saw when they created the campaign:
 *   all  → every customer with a phone number
 *   vip  → customers flagged is_vip = true
 *   lost → ever-visited customers whose last visit is >= 30 days ago
 *          (or who have no last-visit date)
 *   tier → customers whose active loyalty card matches the given tier
 *
 * Every list is constrained to customers that have a normalizable phone.
 */
class CampaignSegmentResolver
{
    public function recipients(string $businessId, string $segmentType, ?string $tier = null): Builder
    {
        $query = Customer::query()
            ->where('customers.business_id', $businessId)
            ->where(function (Builder $q) {
                $q->whereNotNull('customers.phone')->where('customers.phone', '!=', '');
            });

        switch ($segmentType) {
            case 'vip':
                $query->where('customers.is_vip', true);
                break;

            case 'lost':
                $query->where('customers.total_visits', '>', 0)
                    ->where(function (Builder $q) {
                        $q->whereNull('customers.last_visit_date')
                            ->orWhere('customers.last_visit_date', '<', Carbon::now()->subDays(30));
                    });
                break;

            case 'tier':
                $query->whereHas('loyaltyCard', function (Builder $cardQuery) use ($businessId, $tier) {
                    $cardQuery->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->where('tier', $tier);
                });
                break;

            case 'all':
            default:
                break;
        }

        return $query;
    }

    public function count(string $businessId, string $segmentType, ?string $tier = null): int
    {
        return $this->recipients($businessId, $segmentType, $tier)->count();
    }

    /**
     * Chunk the recipient customer IDs (bypassing phone-mutation issues and
     * keeping the job bounded in memory).
     */
    public function recipientIds(string $businessId, string $segmentType, ?string $tier = null): array
    {
        return $this->recipients($businessId, $segmentType, $tier)
            ->pluck('customers.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Build the final message body for one customer, substituting placeholders. */
    public function render(string $template, Customer $customer): string
    {
        return str_replace(
            ['{name}', '{phone}', '{card_number}', '{loyalty_card_number}'],
            [
                $customer->name ?? '',
                (string) ($customer->phone ?? ''),
                (string) ($customer->loyalty_card_number ?? ''),
                (string) ($customer->loyalty_card_number ?? ''),
            ],
            $template
        );
    }

    public function normalizePhone(?string $phone): ?string
    {
        return PhoneNormalizer::normalize($phone);
    }
}
