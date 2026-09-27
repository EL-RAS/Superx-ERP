<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BusinessTypeSeeder::class,
            TenantSeeder::class,
            BusinessSeeder::class,
            CategorySeeder::class,
            ChartOfAccountsSeeder::class,
            OpeningBalanceSeeder::class,
            SuperxOwnerSeeder::class,
        ]);
    }
}
