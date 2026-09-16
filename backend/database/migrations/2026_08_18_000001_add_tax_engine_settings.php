<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('businesses')->select('id', 'settings')->get();

        foreach ($rows as $row) {
            $settings = (array) $row->settings;
            $changed = false;

            $defaults = [
                'tax_enabled' => true,
                'default_tax_rate' => 16,
                'tax_calculation_method' => 'inclusive',
                'jofotara_enabled' => false,
                'jofotara_client_id' => null,
                'jofotara_secret_key' => null,
            ];

            foreach ($defaults as $key => $value) {
                if (! array_key_exists($key, $settings) || $settings[$key] === null && $value !== null) {
                    $settings[$key] = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('businesses')
                    ->where('id', $row->id)
                    ->update(['settings' => $settings]);
            }
        }
    }

    public function down(): void
    {
        $keys = ['tax_enabled', 'default_tax_rate', 'tax_calculation_method', 'jofotara_enabled', 'jofotara_client_id', 'jofotara_secret_key'];

        $rows = DB::table('businesses')->select('id', 'settings')->get();

        foreach ($rows as $row) {
            $settings = (array) $row->settings;

            foreach ($keys as $key) {
                unset($settings[$key]);
            }

            DB::table('businesses')
                ->where('id', $row->id)
                ->update(['settings' => $settings]);
        }
    }
};
