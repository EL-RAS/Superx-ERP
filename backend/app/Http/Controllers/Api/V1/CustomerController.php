<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Rules\ValidPhone;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('loyalty_card_number', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $customers = $query->with('loyaltyCard:id,customer_id,card_number,points_balance,tier')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCustomer($request);
        $validated['business_id'] = $request->user()->business_id;

        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load('loyaltyCard');

        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $this->validateCustomer($request, required: false);
        $customer->update($validated);

        return response()->json($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    /**
     * POS phone lookup â€” finds an existing customer by phone or auto-creates
     * a new one with a default "Client-N" name. Returns the customer and a
     * flag indicating whether they are newly created.
     */
    public function lookupByPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:255',
        ]);

        $query = trim($validated['phone']);
        $normalized = PhoneNormalizer::normalize($query);

        $customer = Customer::where(function ($q) use ($query, $normalized) {
            $q->where('phone', $query);
            if ($normalized && $normalized !== $query) {
                $q->orWhere('phone', $normalized);
            }
            // POS also accepts a loyalty card number or a name fragment.
            $q->orWhere('loyalty_card_number', $query)
                ->orWhere('name', 'ilike', "%{$query}%");
        })->first();

        $newlyCreated = false;

        if (! $customer) {
            $customer = Customer::create([
                'business_id' => $request->user()->business_id,
                'phone' => $normalized ?: $query,
            ]);
            $newlyCreated = true;
        }

        $customer->load('loyaltyCard');

        return response()->json([
            'customer' => $customer,
            'newly_created' => $newlyCreated,
        ]);
    }

    /**
     * Customer segments for campaigns and CRM analytics.
     */
    public function segments(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $totalCustomers = Customer::where('business_id', $businessId)->count();

        $lostCustomers = Customer::where('business_id', $businessId)
            ->where('total_visits', '>', 0)
            ->where(function ($q) use ($thirtyDaysAgo) {
                $q->whereNull('last_visit_date')
                    ->orWhere('last_visit_date', '<', $thirtyDaysAgo);
            })->count();

        $vipCustomers = Customer::where('business_id', $businessId)
            ->where('is_vip', true)
            ->count();

        $newCustomers = Customer::where('business_id', $businessId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $activeCustomers = Customer::where('business_id', $businessId)
            ->where('last_visit_date', '>=', $thirtyDaysAgo)
            ->count();

        return response()->json([
            'total' => $totalCustomers,
            'active' => $activeCustomers,
            'new' => $newCustomers,
            'lost' => $lostCustomers,
            'vip' => $vipCustomers,
            'lost_list' => Customer::where('business_id', $businessId)
                ->where('total_visits', '>', 0)
                ->where(function ($q) use ($thirtyDaysAgo) {
                    $q->whereNull('last_visit_date')
                        ->orWhere('last_visit_date', '<', $thirtyDaysAgo);
                })
                ->orderBy('total_spend', 'desc')
                ->limit(50)
                ->get(['id', 'name', 'phone', 'total_spend', 'last_visit_date']),
            'vip_list' => Customer::where('business_id', $businessId)
                ->where('is_vip', true)
                ->orderByDesc('total_spend')
                ->limit(50)
                ->get(['id', 'name', 'phone', 'total_spend', 'total_visits', 'loyalty_points_balance']),
        ]);
    }

    private function validateCustomer(Request $request, bool $required = true): array
    {
        $nameRule = $required ? 'required|string|max:255' : 'sometimes|string|max:255';
        $phoneRule = $required
            ? ['required', 'string', 'max:40', new ValidPhone]
            : ['sometimes', 'string', 'max:40', new ValidPhone];

        $validated = $request->validate([
            'name' => $nameRule,
            'type' => 'nullable|string|in:customer,patient,guest',
            'email' => ['nullable', 'email:rfc'],
            'phone' => $phoneRule,
            'address' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'notes' => 'nullable|string',
            'size_top' => 'nullable|string|max:50',
            'size_bottom' => 'nullable|string|max:50',
            'shoe_size' => 'nullable|string|max:50',
            'fit_preference' => 'nullable|string|max:50',
            'preferred_brands' => 'nullable|array',
            'is_vip' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($request->filled('phone')) {
            $validated['phone'] = PhoneNormalizer::normalize($request->input('phone'));
        }

        if ($required && ! empty($validated['phone'])) {
            $exists = Customer::where('business_id', $request->user()->business_id)
                ->where('phone', $validated['phone'])
                ->when($request->route('customer'), fn ($q) => $q->where('id', '!=', $request->route('customer')->id))
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['phone' => ['A customer with this phone number already exists.']],
                ], 422);
            }
        }

        return $validated;
    }

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'void'])
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderBy('created_at')
            ->get(['id', 'invoice_number', 'created_at', 'net_amount', 'payment_status']);

        $payments = Payment::where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderBy('created_at')
            ->get(['id', 'payment_number', 'created_at', 'amount', 'method']);

        $refunds = Payment::where('customer_id', $customer->id)
            ->where('status', 'refunded')
            ->where('refunded_amount', '>', 0)
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderBy('created_at')
            ->get(['id', 'payment_number', 'created_at', 'refunded_amount', 'method']);

        $lines = [];

        foreach ($invoices as $invoice) {
            $lines[] = [
                'date' => $invoice->created_at->toDateString(),
                'reference' => $invoice->invoice_number,
                'type' => 'invoice',
                'description' => 'Invoice '.$invoice->invoice_number,
                'debit' => round((float) $invoice->net_amount, 2),
                'credit' => 0,
            ];
        }

        foreach ($payments as $payment) {
            $lines[] = [
                'date' => $payment->created_at->toDateString(),
                'reference' => $payment->payment_number,
                'type' => 'payment',
                'description' => 'Payment ('.($payment->method ?? 'cash').')',
                'debit' => 0,
                'credit' => round((float) $payment->amount, 2),
            ];
        }

        foreach ($refunds as $refund) {
            $lines[] = [
                'date' => $refund->created_at->toDateString(),
                'reference' => $refund->payment_number,
                'type' => 'refund',
                'description' => 'Payment refunded ('.($refund->method ?? 'cash').')',
                'debit' => round((float) $refund->refunded_amount, 2),
                'credit' => 0,
            ];
        }

        usort($lines, fn ($a, $b) => $a['date'] <=> $b['date'] ?: strcmp($a['reference'], $b['reference']));

        $running = 0;
        foreach ($lines as &$line) {
            $running += $line['debit'] - $line['credit'];
            $line['balance'] = round($running, 2);
        }
        unset($line);

        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        return response()->json([
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'account_code' => '1040',
            'account_name' => 'Accounts Receivable',
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balance' => round($totalDebit - $totalCredit, 2),
        ]);
    }

    /**
     * Delivery app webhook â€” accepts order data from external platforms
     * (Talabat, Careem, etc.) and maps customer data into the CRM.
     */
    public function deliveryWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|max:100',
            'order_id' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:255',
            'customer_name' => 'nullable|string|max:255',
            'order_total' => 'required|numeric|min:0',
            'delivery_address' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.name' => 'sometimes|string',
            'items.*.quantity' => 'sometimes|numeric|min:1',
            'items.*.price' => 'sometimes|numeric|min:0',
        ]);

        $businessId = $request->header('X-Business-ID');
        if (! $businessId) {
            return response()->json(['message' => 'Missing X-Business-ID header.'], 422);
        }

        $normalized = PhoneNormalizer::normalize($validated['customer_phone']);

        $customer = Customer::where('business_id', $businessId)
            ->where('phone', $normalized ?: $validated['customer_phone'])
            ->first();

        $newlyCreated = false;

        if (! $customer) {
            $customer = Customer::create([
                'business_id' => $businessId,
                'phone' => $normalized ?: $validated['customer_phone'],
                'name' => $validated['customer_name'] ?? null,
                'address' => $validated['delivery_address'] ?? null,
                'delivery_notes' => $validated['delivery_notes'] ?? null,
            ]);
            $newlyCreated = true;
        } else {
            $customer->update([
                'address' => $validated['delivery_address'] ?? $customer->address,
                'delivery_notes' => $validated['delivery_notes'] ?? $customer->delivery_notes,
            ]);
        }

        return response()->json([
            'customer' => $customer,
            'newly_created' => $newlyCreated,
            'order_id' => $validated['order_id'],
            'platform' => $validated['platform'],
            'message' => 'Customer data synced from '.$validated['platform'],
        ], $newlyCreated ? 201 : 200);
    }
}
