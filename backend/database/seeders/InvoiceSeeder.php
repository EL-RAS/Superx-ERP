<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $businesses = Business::all();

        foreach ($businesses as $business) {
            $user = User::where('business_id', $business->id)->first();
            if (!$user) continue;

            $statuses = ['paid', 'paid', 'paid', 'partial', 'unpaid'];

            for ($i = 1; $i <= 15; $i++) {
                $total = rand(1500, 12500) / 100;
                $tax = round($total * 0.14, 4);
                $discount = rand(0, 500) / 100;
                $status = $statuses[array_rand($statuses)];

                Invoice::create([
                    'business_id' => $business->id,
                    'user_id' => $user->id,
                    'invoice_number' => 'INV-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'total_amount' => $total,
                    'tax_amount' => $tax,
                    'discount_amount' => $discount,
                    'net_amount' => $total + $tax - $discount,
                    'payment_status' => $status,
                ]);
            }
        }
    }
}
