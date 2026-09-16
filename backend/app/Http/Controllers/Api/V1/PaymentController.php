<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('payment_number', 'ilike', "%{$search}%");
        }

        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->input('invoice_id'));
        }

        if ($request->has('method')) {
            $query->where('method', $request->input('method'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $payments = $query->with(['invoice:id,invoice_number', 'customer:id,name', 'user:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $paidAmount = (float) $invoice->payments()->where('status', 'completed')->sum('amount');
            $balance = (float) $invoice->net_amount - $paidAmount;

            if ($validated['amount'] > $balance + 0.01) {
                return response()->json([
                    'message' => "Payment amount ({$validated['amount']}) exceeds remaining balance ({$balance}).",
                ], 422);
            }

            $openShift = Shift::openFor($request->user()->business_id, $request->user()->id);

            $payment = Payment::create([
                'business_id' => $request->user()->business_id,
                'user_id' => $request->user()->id,
                'invoice_id' => $validated['invoice_id'],
                'shift_id' => $openShift?->id,
                'customer_id' => $invoice->customer_id,
                'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'completed',
                'refunded_amount' => 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            $invoice->recalculatePaymentStatus();

            $accounting = app(AccountingService::class);
            if ($invoice->status !== 'void') {
                $accounting->postSaleEntry($request->user()->business_id, $invoice, $request->user()->id);
            }
            $accounting->postInvoicePaymentEntry($request->user()->business_id, $payment, $request->user()->id);

            $payment->load(['invoice:id,invoice_number,net_amount', 'customer:id,name', 'user:id,name']);

            return response()->json($payment, 201);
        });
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['invoice:id,invoice_number,net_amount', 'customer:id,name', 'user:id,name']);

        return response()->json($payment);
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'method' => 'sometimes|string',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated, $payment) {
            if (isset($validated['amount']) || $request->has('amount')) {
                if (in_array($payment->status, ['refunded', 'partial_refund'])) {
                    return response()->json([
                        'message' => 'Cannot change amount on a payment that has been refunded.',
                    ], 422);
                }
            }

            $payment->update($validated);

            return response()->json($payment);
        });
    }

    public function destroy(Payment $payment): JsonResponse
    {
        return DB::transaction(function () use ($payment) {
            $invoice = $payment->invoice;

            $accounting = app(AccountingService::class);
            if ($accounting->hasEntry($payment->business_id, 'invoice_payment', $payment->id)) {
                $accounting->postPaymentRefund($payment->business_id, $payment, (float) $payment->amount);
            }

            $payment->delete();

            if ($invoice) {
                $invoice->recalculatePaymentStatus();
            }

            return response()->json(['message' => 'Payment deleted.']);
        });
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->status === 'refunded') {
            return response()->json(['message' => 'Payment is already fully refunded.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $refundable = $payment->refundable;

        if ($validated['amount'] > $refundable + 0.01) {
            return response()->json([
                'message' => "Refund amount ({$validated['amount']}) exceeds refundable amount ({$refundable}).",
            ], 422);
        }

        $newRefunded = (float) $payment->refunded_amount + $validated['amount'];

        $payment->update([
            'refunded_amount' => $newRefunded,
            'status' => $newRefunded >= (float) $payment->amount ? 'refunded' : 'partial_refund',
        ]);

        if ($payment->invoice_id) {
            $payment->invoice->recalculatePaymentStatus();
        }

        app(AccountingService::class)->postPaymentRefund($payment->business_id, $payment, (float) $validated['amount'], $request->user()->id);

        return response()->json($payment);
    }
}
