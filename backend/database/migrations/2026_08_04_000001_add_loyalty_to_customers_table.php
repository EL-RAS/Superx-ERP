<?php

use App\Models\Customer;
use App\Models\LoyaltyCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('loyalty_card_number')->nullable()->after('notes');
            $table->integer('loyalty_points_balance')->default(0)->after('loyalty_card_number');
            $table->string('tier_level')->default('bronze')->after('loyalty_points_balance');
            $table->index(['business_id', 'loyalty_card_number']);
        });

        // Backfill: existing customers get a unique card number, sync their
        // points/tier with their legacy loyalty card (creating one when absent),
        // so the customer-level loyalty columns are immediately usable.
        Customer::withoutBusiness()->with('loyaltyCards')->chunkById(200, function ($customers) {
            foreach ($customers as $customer) {
                $card = $customer->loyaltyCards()->first();

                if ($card) {
                    $customer->loyalty_card_number = $card->card_number;
                    $customer->loyalty_points_balance = (int) $card->points_balance;
                    $customer->tier_level = $card->tier;
                    $customer->save();

                    continue;
                }

                $number = $this->uniqueCardNumber((string) $customer->business_id);
                LoyaltyCard::create([
                    'business_id' => $customer->business_id,
                    'customer_id' => $customer->id,
                    'card_number' => $number,
                    'points_balance' => 0,
                    'total_points_earned' => 0,
                    'total_points_redeemed' => 0,
                    'total_spend' => 0,
                    'tier' => 'bronze',
                    'is_active' => true,
                ]);

                $customer->loyalty_card_number = $number;
                $customer->loyalty_points_balance = 0;
                $customer->tier_level = 'bronze';
                $customer->save();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'loyalty_card_number']);
            $table->dropColumn(['loyalty_card_number', 'loyalty_points_balance', 'tier_level']);
        });
    }

    private function uniqueCardNumber(string $businessId): string
    {
        do {
            $number = 'LOY-'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while (
            Customer::withoutBusiness()->where('business_id', $businessId)->where('loyalty_card_number', $number)->exists()
            || LoyaltyCard::withoutBusiness()->where('business_id', $businessId)->where('card_number', $number)->exists()
        );

        return $number;
    }
};
