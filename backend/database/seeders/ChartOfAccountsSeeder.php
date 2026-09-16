<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Services\AccountingService;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(AccountingService::class);

        foreach (Business::all() as $business) {
            $service->ensureChartOfAccounts($business->id);
        }
    }
}
