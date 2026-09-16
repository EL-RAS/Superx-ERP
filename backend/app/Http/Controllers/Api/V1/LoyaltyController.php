<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyTransaction;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoyaltyController extends Controller
{
    public function cards(Request $request): JsonResponse
    {
        $query = LoyaltyCard::query()->with('customer:id,name,email,phone');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('tier')) {
            $query->where('tier', $request->input('tier'));
        }

        $cards = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($cards);
    }

    public function storeCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $request->user()->business_id)],
            'card_number' => [
                'required',
                'string',
                Rule::unique('loyalty_cards', 'card_number')->where(fn ($query) => $query->where('business_id', $request->user()->business_id)),
            ],
        ]);

        $card = LoyaltyCard::create([
            'business_id' => $request->user()->business_id,
            'customer_id' => $validated['customer_id'],
            'card_number' => $validated['card_number'],
            'points_balance' => 0,
            'total_points_earned' => 0,
            'total_points_redeemed' => 0,
            'total_spend' => 0,
            'tier' => 'bronze',
            'is_active' => true,
        ]);

        $card->load('customer:id,name,email,phone');

        return response()->json($card, 201);
    }

    public function showCard(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $loyaltyCard->load('customer:id,name,email,phone');
        $loyaltyCard->load('transactions');

        return response()->json($loyaltyCard);
    }

    public function updateCard(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $validated = $request->validate([
            'tier' => 'sometimes|string|in:bronze,silver,gold,platinum',
            'is_active' => 'sometimes|boolean',
        ]);

        $loyaltyCard->update($validated);
        $loyaltyCard->load('customer:id,name,email,phone');

        return response()->json($loyaltyCard);
    }

    public function destroyCard(LoyaltyCard $loyaltyCard): JsonResponse
    {
        $loyaltyCard->delete();

        return response()->json(['message' => 'Loyalty card deleted.']);
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = LoyaltyTransaction::query()->with('loyaltyCard:id,card_number,customer_id');

        if ($request->has('loyalty_card_id')) {
            $query->where('loyalty_card_id', $request->input('loyalty_card_id'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($transactions);
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'loyalty_card_id' => ['required', Rule::exists('loyalty_cards', 'id')->where('business_id', $request->user()->business_id)],
            'type' => 'required|in:earn,redeem,adjust',
            'points' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            // Row lock prevents concurrent redeems from both passing the
            // balance check and overdrawing the card.
            $card = LoyaltyCard::where('id', $validated['loyalty_card_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($validated['type'] === 'redeem' && $card->points_balance < $validated['points']) {
                return response()->json([
                    'message' => 'Insufficient points balance.',
                    'current_balance' => $card->points_balance,
                    'requested' => $validated['points'],
                ], 422);
            }

            $pointsChange = match ($validated['type']) {
                'earn' => $validated['points'],
                'redeem' => -$validated['points'],
                'adjust' => $validated['points'],
            };

            $card->update(['points_balance' => $card->points_balance + $pointsChange]);

            if ($validated['type'] === 'earn') {
                $card->increment('total_points_earned', $validated['points']);
            } elseif ($validated['type'] === 'redeem') {
                $card->increment('total_points_redeemed', $validated['points']);
            }

            $transaction = LoyaltyTransaction::create([
                'business_id' => $request->user()->business_id,
                'loyalty_card_id' => $card->id,
                'type' => $validated['type'],
                'points' => $validated['points'],
                'balance_after' => $card->points_balance,
                'description' => $validated['description'] ?? null,
            ]);

            // Keep the customer-level loyalty columns (CRM + POS source of
            // truth) in sync with the legacy loyalty card.
            if ($card->customer_id) {
                $customer = Customer::whereKey($card->customer_id)->first();
                if ($customer) {
                    $customer->update([
                        'loyalty_points_balance' => $card->points_balance,
                        'tier_level' => $card->tier,
                    ]);
                    $customer->recalcTierLevel();
                    $customer->recalcVip();
                }
            }

            $card->load('customer:id,name,email,phone');

            return response()->json([
                'transaction' => $transaction,
                'card' => $card,
            ], 201);
        });
    }

    public function lookupByPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
        ]);

        $query = trim($validated['phone']);
        $normalized = PhoneNormalizer::normalize($query);

        $card = LoyaltyCard::whereHas('customer', function ($q) use ($query, $normalized) {
            $q->where('phone', $query);
            if ($normalized) {
                $q->orWhere('phone', $normalized);
            }
            $q->orWhere('loyalty_card_number', $query);
        })
            ->with('customer:id,name,email,phone')
            ->first();

        if (! $card) {
            return response()->json(['message' => 'No loyalty card found for this phone number.'], 404);
        }

        return response()->json($card);
    }

    public function config(Request $request): JsonResponse
    {
        $settings = $request->user()->business->mergedSettings();

        $earnRate = (int) ($settings['loyalty_earn_rate'] ?? 1);
        $redemptionRate = (int) ($settings['loyalty_redemption_rate'] ?? 100);
        $alertThreshold = (int) ($settings['loyalty_alert_threshold'] ?? 500);

        return response()->json([
            'loyalty_enabled' => ($settings['loyalty_enabled'] ?? false) === true,
            'points_per_jod' => $earnRate,
            'redemption_rate' => round(1 / $redemptionRate, 4),
            'redemption_rate_display' => $redemptionRate,
            'alert_threshold' => $alertThreshold,
            'alert_discount' => round($alertThreshold / $redemptionRate, 2),
            'tiers' => [
                'bronze' => ['min_spend' => 0, 'points_multiplier' => 1],
                'silver' => ['min_spend' => 500, 'points_multiplier' => 1.25],
                'gold' => ['min_spend' => 2000, 'points_multiplier' => 1.5],
                'platinum' => ['min_spend' => 5000, 'points_multiplier' => 2],
            ],
        ]);
    }
}
