<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE customers SET
                total_spend = COALESCE((
                    SELECT ROUND(SUM(i.net_amount), 2)
                    FROM invoices i
                    WHERE i.customer_id = customers.id
                    AND i.status NOT IN (\'draft\', \'void\')
                ), 0),
                total_visits = COALESCE((
                    SELECT COUNT(*)
                    FROM invoices i
                    WHERE i.customer_id = customers.id
                    AND i.status NOT IN (\'draft\', \'void\')
                ), 0),
                last_visit_date = (
                    SELECT MAX(i.created_at)
                    FROM invoices i
                    WHERE i.customer_id = customers.id
                    AND i.status NOT IN (\'draft\', \'void\')
                )
            WHERE EXISTS (
                SELECT 1 FROM invoices i WHERE i.customer_id = customers.id
            )
        ');
    }

    public function down(): void
    {
        DB::table('customers')->update([
            'total_spend' => 0,
            'total_visits' => 0,
            'last_visit_date' => null,
        ]);
    }
};
