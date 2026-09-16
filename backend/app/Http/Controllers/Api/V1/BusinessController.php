<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    public function __invoke()
    {
        return response()->json(
            Business::where('status', 'active')
                ->with('businessType:id,slug,name_en,name_ar')
                ->get(['id', 'name', 'slug', 'business_type_id'])
        );
    }

    public function settings(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        return response()->json([
            'settings' => $this->publicSettings($this->mergedSettings($business)),
            'next_numbers' => $this->documentNumbers($business),
            'logo' => $business->logo,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => 'sometimes|string|max:255|not_regex:/^\s*$/',
            'admin_name' => 'sometimes|string|max:255|not_regex:/^\s*$/',
            'admin_email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                Rule::unique('users', 'email')
                    ->where('business_id', $request->user()->business_id)
                    ->ignore($request->user()->id),
            ],
            'allow_split_payments' => 'sometimes|boolean',
            'allow_credit_sales' => 'sometimes|boolean',
            'expiry_alerts' => 'sometimes|boolean',
            'rapid_mode' => 'sometimes|boolean',
            'loyalty_enabled' => 'sometimes|boolean',
            'promotions_enabled' => 'sometimes|boolean',
            'low_stock_sensitivity' => 'sometimes|string|in:low,normal,strict',
            'sales_invoice_prefix' => 'sometimes|string|max:20|not_regex:/^\s*$/',
            'purchase_order_prefix' => 'sometimes|string|max:20|not_regex:/^\s*$/',
            'grn_prefix' => 'sometimes|string|max:20|not_regex:/^\s*$/',
            'invoice_footer_terms' => 'sometimes|nullable|string|max:5000',
            'logo' => 'sometimes|nullable|string|max:2097152',
            'tax_enabled' => 'sometimes|boolean',
            'default_tax_rate' => 'sometimes|nullable|numeric|min:0|max:100',
            'tax_calculation_method' => 'sometimes|string|in:inclusive,exclusive',
            'jofotara_enabled' => 'sometimes|boolean',
            'jofotara_client_id' => 'sometimes|nullable|string|max:255',
            'jofotara_secret_key' => 'sometimes|nullable|string|max:255',
            'loyalty_earn_rate' => 'sometimes|nullable|numeric|min:1|max:100',
            'loyalty_redemption_rate' => 'sometimes|nullable|numeric|min:1|max:1000',
            'loyalty_alert_threshold' => 'sometimes|nullable|numeric|min:0|max:10000',
            'tax_number' => 'sometimes|nullable|string|max:50',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:500',
            'auto_print_receipt' => 'sometimes|boolean',
            'receipt_paper_width' => 'sometimes|string|in:80mm,58mm',
            'receipt_footer_message' => 'sometimes|nullable|string|max:5000',
            'allow_negative_stock' => 'sometimes|boolean',
            'scale_barcode_parsing' => 'sometimes|boolean',
            'scale_barcode_prefix' => 'sometimes|nullable|string|max:5|regex:/^\d+$/',
            'expiry_warning_days' => 'sometimes|nullable|integer|min:1|max:365',
        ], [
            'business_name.string' => 'The business name cannot be blank.',
            'business_name.not_regex' => 'The business name cannot be blank.',
            'admin_name.string' => 'The admin name cannot be blank.',
            'admin_name.not_regex' => 'The admin name cannot be blank.',
            'sales_invoice_prefix.string' => 'The sales invoice prefix cannot be empty.',
            'sales_invoice_prefix.not_regex' => 'The sales invoice prefix cannot be empty.',
            'purchase_order_prefix.string' => 'The purchase order prefix cannot be empty.',
            'purchase_order_prefix.not_regex' => 'The purchase order prefix cannot be empty.',
            'grn_prefix.string' => 'The goods receipt prefix cannot be empty.',
            'grn_prefix.not_regex' => 'The goods receipt prefix cannot be empty.',
        ]);

        $business = $request->user()->business;
        $user = $request->user();

        if (array_key_exists('business_name', $validated)) {
            $business->name = $validated['business_name'] ?? $business->name;
        }

        if (array_key_exists('admin_name', $validated)) {
            $user->name = $validated['admin_name'] ?? $user->name;
        }

        if (array_key_exists('admin_email', $validated)) {
            $user->email = $validated['admin_email'] ?? $user->email;
        }

        if (array_key_exists('logo', $validated)) {
            $business->logo = $validated['logo'] ?? null;
        }

        if ($business->isDirty()) {
            $business->save();
        }

        if ($user->isDirty()) {
            $user->save();
        }

        $settings = $this->mergedSettings($business);
        foreach (['allow_split_payments', 'allow_credit_sales', 'expiry_alerts', 'rapid_mode', 'loyalty_enabled', 'loyalty_earn_rate', 'loyalty_redemption_rate', 'loyalty_alert_threshold', 'promotions_enabled', 'low_stock_sensitivity', 'sales_invoice_prefix', 'purchase_order_prefix', 'grn_prefix', 'invoice_footer_terms', 'tax_enabled', 'default_tax_rate', 'tax_calculation_method', 'jofotara_enabled', 'jofotara_client_id', 'tax_number', 'phone', 'address', 'auto_print_receipt', 'receipt_paper_width', 'receipt_footer_message', 'allow_negative_stock', 'scale_barcode_parsing', 'scale_barcode_prefix', 'expiry_warning_days'] as $key) {
            if (array_key_exists($key, $validated)) {
                $settings[$key] = $validated[$key];
            }
        }

        // The JoFotara secret is write-only: it is never echoed back and, once
        // stored, a blank value keeps the existing key (the UI only re-reads
        // what the admin just typed, so editing unrelated settings must never
        // wipe the configured secret).
        if (array_key_exists('jofotara_secret_key', $validated)
            && is_string($validated['jofotara_secret_key'])
            && trim($validated['jofotara_secret_key']) !== ''
        ) {
            $settings['jofotara_secret_key'] = $validated['jofotara_secret_key'];
        }
        $business->settings = $settings;
        $business->save();

        return response()->json([
            'settings' => $this->publicSettings($business->settings),
            'business_name' => $business->name,
            'logo' => $business->logo,
            'user' => ['name' => $user->name, 'email' => $user->email],
            'next_numbers' => $this->documentNumbers($business),
        ]);
    }

    private function mergedSettings(Business $business): array
    {
        return $business->mergedSettings();
    }

    /** Settings safe to send to any consumer: the JoFotara secret is masked. */
    private function publicSettings(array $settings): array
    {
        $settings['jofotara_secret_key'] = null;

        return $settings;
    }

    private function documentNumbers(Business $business): array
    {
        $settings = $this->mergedSettings($business);

        return [
            'sales_invoice' => DocumentNumberService::peek($settings, 'invoice', $business->id),
            'purchase_order' => DocumentNumberService::peek($settings, 'purchase_order', $business->id),
            'grn' => DocumentNumberService::peek($settings, 'grn', $business->id),
        ];
    }
}
