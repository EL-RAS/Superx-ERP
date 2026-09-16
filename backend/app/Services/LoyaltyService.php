<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    public function earnForInvoice(Invoice $invoice): void
    {
        if ($invoice->status === 'void' || empty($invoice->customer_id)) {
            return;
        }

        if ($invoice->payment_status !== 'paid') {
            return;
        }

        $alreadyEarned = LoyaltyTransaction::where('invoice_id', $invoice->id)
            ->where('type', 'earn')
            ->exists();
        if ($alreadyEarned) {
            return;
        }

        DB::transaction(function () use ($invoice) {
            $customer = Customer::whereKey($invoice->customer_id)->lockForUpdate()->first();
            if (! $customer) {
                return;
            }

            $settings = $invoice->business->mergedSettings();
            $earnRate = (int) ($settings['loyalty_earn_rate'] ?? 1);

            $points = (int) floor((float) $invoice->net_amount / $earnRate);
            if ($points <= 0) {
                $this->updateCustomerMetrics($customer, $invoice);
                return;
            }

            $card = LoyaltyCard::where('customer_id', $customer->id)->lockForUpdate()->first();
            if (! $card) {
                return;
            }

            $card->increment('points_balance', $points);
            $card->increment('total_points_earned', $points);
            $card->increment('total_spend', (float) $invoice->net_amount);
            $card->upgradeTier();

            $customer->update([
                'tier_level' => $card->tier,
                'loyalty_points_balance' => $card->points_balance,
            ]);

            LoyaltyTransaction::create([
                'business_id' => $invoice->business_id,
                'loyalty_card_id' => $card->id,
                'type' => 'earn',
                'points' => $points,
                'balance_after' => $card->points_balance,
                'description' => 'Purchase '.$invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'points_expiry_date' => now()->addYear()->toDateString(),
            ]);

            $this->updateCustomerMetrics($customer, $invoice);

            $customer->recalcTierLevel();
            $customer->recalcVip();
        });
    }

    private function updateCustomerMetrics(Customer $customer, Invoice $invoice): void
    {
        $customer->increment('total_spend', (float) $invoice->net_amount);
        $customer->increment('total_visits');
        $customer->update(['last_visit_date' => $invoice->created_at]);
    }
}
