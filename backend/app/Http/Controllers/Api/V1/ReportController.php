<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function trialBalance(Request $request): JsonResponse
    {
        return response()->json(app(ReportService::class)->trialBalance(
            $request->input('date_from'),
            $request->input('date_to', now()->toDateString())
        ));
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        return response()->json(app(ReportService::class)->profitAndLoss(
            $request->input('date_from', now()->startOfYear()->toDateString()),
            $request->input('date_to', now()->toDateString())
        ));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        return response()->json(app(ReportService::class)->balanceSheet(
            $request->input('date_to', now()->toDateString())
        ));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        return response()->json(app(ReportService::class)->cashFlow(
            $request->input('date_from', now()->startOfYear()->toDateString()),
            $request->input('date_to', now()->toDateString())
        ));
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('business_id', $request->user()->business_id))],
        ]);

        $account = Account::findOrFail($request->input('account_id'));

        return response()->json(app(ReportService::class)->generalLedger(
            $account,
            $request->input('date_from'),
            $request->input('date_to')
        ));
    }

    public function salesSummary(Request $request): JsonResponse
    {
        return response()->json(app(ReportService::class)->salesSummary(
            $request->input('date_from', now()->startOfYear()->toDateString()),
            $request->input('date_to', now()->toDateString())
        ));
    }

    public function stockValuation(): JsonResponse
    {
        return response()->json(app(ReportService::class)->stockValuation());
    }

    public function supplierAging(): JsonResponse
    {
        return response()->json(app(ReportService::class)->supplierAging());
    }
}
